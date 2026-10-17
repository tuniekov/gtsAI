<?php

/**
 * gtsAI — шлюз ИИ для сайта.
 *
 * Запросы приходят на /gtsai (плагин elements/plugins/gtsai.php на OnHandleRequest)
 * и попадают сюда. Ключевое следствие такого входа: MODX к этому моменту уже
 * загружен, значит $modx->user и группы на месте — правило «ИИ может ровно то,
 * что может текущий пользователь» достаётся без собственной системы прав.
 *
 * Маршруты:
 *   GET  /gtsai/settings?name=X   настройки виджета для текущего юзера (без ключа!)
 *   GET  /gtsai/chats?setting=X   список чатов юзера
 *   GET  /gtsai/chat/{id}         сообщения чата
 *   POST /gtsai/message           ответ ИИ стримом (SSE)
 *   GET  /gtsai/echo              тестовый стрим без провайдера
 */
class gtsAI
{
    /** @var modX */
    public $modx;

    /** @var array */
    public $config = [];

    /** @var array текущая настройка виджета (gtsAISetting) */
    protected $setting = [];

    /** @var array подключение (gtsAIConn) */
    protected $conn = [];

    /** @var gtsAIBridge мост к gtsAPI, создаётся при первом инструменте */
    protected $bridge = null;

    /** @var xPDOObject|null текущий чат */
    protected $chat = null;

    /** @var string пакет правок текущего ответа (для авто-отката при обрыве) */
    protected $turnBatch = '';

    /** @var bool спрашивать ли подтверждение в этом ответе */
    protected $askConfirm = true;

    /** @var PDO|null соединение только на чтение (отдельный пользователь базы) */
    protected $sqlPdo = null;

    /** @var array|null доступы читающего пользователя, прочитанные один раз */
    protected $sqlCreds = null;

    /** @var array|null доппромпты настройки, прочитанные один раз */
    protected $extras = null;

    /**
     * Сколько байт шагов хода храним и пересылаем обратно. Больше - дороже
     * каждое следующее сообщение в этом чате: история уходит провайдеру целиком.
     */
    const TURN_KEEP_BYTES = 60000;

    /** @var bool ответ дошёл до конца штатно */
    protected $turnDone = false;

    public function __construct(modX &$modx, array $config = [])
    {
        $this->modx =& $modx;
        $corePath = MODX_CORE_PATH . 'components/gtsai/';
        $this->config = array_merge([
            'corePath'    => $corePath,
            'modelPath'   => $corePath . 'model/',
            'classesPath' => $corePath . 'classes/',
        ], $config);

        $this->modx->addPackage('gtsai', $this->config['modelPath']);
        require_once $this->config['classesPath'] . 'provider.class.php';
    }

    public function success($message = '', array $data = [])
    {
        return array_merge(['success' => true, 'message' => $message], $data);
    }

    public function error($message = '', array $data = [])
    {
        return array_merge(['success' => false, 'message' => $message], $data);
    }

    /**
     * Маршрутизация. $uri — разобранный REQUEST_URI: [0]='' [1]='gtsai' [2]=action
     *
     * Стримовые действия отдают ответ сами и выходят через exit — наружу они
     * не возвращают ничего.
     */
    public function route(array $uri, $method, array $request)
    {
        $action = isset($uri[2]) ? $uri[2] : '';
        $id     = isset($uri[3]) ? (int)$uri[3] : 0;

        // Тело POST приходит JSON-ом, $_REQUEST его не содержит.
        $raw = file_get_contents('php://input');
        if ($raw !== '' && ($json = json_decode($raw, true)) && is_array($json)) {
            $request = array_merge($request, $json);
        }

        if (empty($this->modx->user->id) || $this->modx->user->id <= 0) {
            return $this->error('Нужно войти на сайт.');
        }

        switch ($action) {
            case 'echo':     return $this->actionEcho($request);
            case 'settings': return $this->actionSettings($request);
            case 'chats':    return $this->actionChats($request);
            case 'chat':     return $this->actionChat($id);
            case 'message':  return $this->actionMessage($request);
            case 'models':   return $this->actionModels($request);
            case 'upload':   return $this->actionUpload($request);
            case 'file':     return $this->actionFile($id);
            case 'apply':    return $this->actionApply($request);
            case 'rollback': return $this->actionRollback($request);
        }
        return $this->error('Неизвестное действие gtsAI: ' . $action);
    }

    /* ------------------------------------------------------------------ *
     *  Настройки и доступ
     * ------------------------------------------------------------------ */

    /**
     * Загрузить настройку виджета по имени и проверить право текущего юзера.
     * Имя приходит с клиента, но что оно означает — решает база, не клиент.
     */
    protected function loadSetting($name)
    {
        $name = trim((string)$name);
        if ($name === '') return $this->error('Не задано имя настроек виджета.');

        $obj = $this->modx->getObject('gtsAISetting', ['name' => $name]);
        if (!$obj) return $this->error('Настройки ИИ «' . $name . '» не найдены.');

        $setting = $obj->toArray();
        if (empty($setting['active'])) return $this->error('Чат ИИ выключен для «' . $name . '».');

        if (!empty($setting['groups'])) {
            $groups = array_filter(array_map('trim', explode(',', $setting['groups'])));
            if ($groups && !$this->modx->user->isMember($groups)) {
                return $this->error('Нет доступа к чату ИИ.');
            }
        }

        $conn = [];
        if (!empty($setting['conn_id'])) {
            if ($c = $this->modx->getObject('gtsAIConn', ['id' => (int)$setting['conn_id'], 'active' => 1])) {
                $conn = $c->toArray();
            }
        }
        if (empty($conn)) {
            if ($c = $this->modx->getObject('gtsAIConn', ['active' => 1])) $conn = $c->toArray();
        }
        if (empty($conn)) return $this->error('Не настроено подключение к ИИ.');

        // Подключение пакет везёт с собой, а ключ — нет: его вписывают на месте.
        // Без этой проверки запрос уходил бы к провайдеру и возвращался сухим
        // «Провайдер ответил 401», по которому непонятно, что делать.
        if (trim((string)$conn['api_key']) === '') {
            return $this->error('У подключения «' . $conn['name'] . '» не заполнен ключ: '
                . 'Компоненты → ИИ (gtsAI) → Подключения.');
        }

        if (!empty($setting['model'])) $conn['model'] = $setting['model'];

        $this->setting = $setting;
        $this->conn    = $conn;
        return $this->success();
    }

    /** Настройки для виджета. Ключ и адрес шлюза наружу не отдаём никогда. */
    protected function actionSettings(array $request)
    {
        $resp = $this->loadSetting(isset($request['setting']) ? $request['setting'] : '');
        if (!$resp['success']) return $resp;

        return $this->success('', ['data' => [
            'name'          => $this->setting['name'],
            'title'         => $this->setting['title'],
            'model'         => $this->conn['model'],
            'save_history'  => (int)$this->setting['save_history'],
            'allow_write'   => (int)$this->setting['allow_write'],
            'confirm_write' => (int)$this->setting['confirm_write'],
            'confirm_lock'  => (int)$this->setting['confirm_lock'],
        ]]);
    }

    /* ------------------------------------------------------------------ *
     *  Чаты
     * ------------------------------------------------------------------ */

    protected function actionChats(array $request)
    {
        $resp = $this->loadSetting(isset($request['setting']) ? $request['setting'] : '');
        if (!$resp['success']) return $resp;

        $c = $this->modx->newQuery('gtsAIChat');
        $c->where(['setting' => $this->setting['name'], 'deleted' => 0]);
        // Админ видит все чаты, остальные — свои.
        if (!$this->modx->user->isMember(['Administrator'])) {
            $c->where(['user_id' => $this->modx->user->id]);
        }
        $c->sortby('updatedon', 'DESC');
        $c->limit(50);

        $rows = [];
        foreach ($this->modx->getCollection('gtsAIChat', $c) as $chat) {
            $rows[] = $chat->toArray();
        }
        return $this->success('', ['rows' => $rows]);
    }

    protected function actionChat($id)
    {
        if (!$id) return $this->error('Не указан чат.');
        if (!$chat = $this->modx->getObject('gtsAIChat', $id)) return $this->error('Чат не найден.');
        if (!$this->canSeeChat($chat)) return $this->error('Нет доступа к этому чату.');

        $c = $this->modx->newQuery('gtsAIMessage');
        $c->where(['chat_id' => $chat->id]);
        $c->sortby('id', 'ASC');

        $rows = [];
        foreach ($this->modx->getCollection('gtsAIMessage', $c) as $msg) {
            $rows[] = $msg->toArray();
        }
        return $this->success('', ['chat' => $chat->toArray(), 'rows' => $rows]);
    }

    protected function canSeeChat($chat)
    {
        if ($chat->user_id == $this->modx->user->id) return true;
        return (bool)$this->modx->user->isMember(['Administrator']);
    }

    /* ------------------------------------------------------------------ *
     *  Стрим
     * ------------------------------------------------------------------ */

    /**
     * Подготовка к SSE. Три тормоза PHP-стрима, каждый из которых по отдельности
     * превращает «живой» чат в «страница зависла»:
     *
     * 1. Лок сессии. MODX держит сессию открытой до конца запроса, и пока чат
     *    отвечает, ВСЕ остальные запросы этого юзера стоят в очереди — страница
     *    просто не грузится. Закрываем сессию до первого байта.
     * 2. Буферизация. Без сброса буферов ответ уедет одним куском в конце:
     *    nginx на проде буферизует, пока не увидит X-Accel-Buffering: no.
     * 3. Время и обрыв. set_time_limit(0) — ответ может идти минуты;
     *    ignore_user_abort(false) — закрыл вкладку, скрипт умер и не жжёт токены.
     */
    protected function sseStart()
    {
        if (function_exists('session_write_close')) session_write_close();

        while (ob_get_level() > 0) ob_end_flush();
        ob_implicit_flush(true);

        header('Content-Type: text/event-stream; charset=UTF-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        set_time_limit(0);
        ignore_user_abort(false);
    }

    protected function sse($event, array $data = [])
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
        flush();
    }

    /**
     * Тестовый стрим без провайдера: проверяет весь путь до браузера —
     * сессию, буферизацию, фронт — и ничего не стоит.
     */
    protected function actionEcho(array $request)
    {
        $this->sseStart();
        $text = isset($request['text']) ? (string)$request['text'] : 'Проверка стрима gtsAI';
        $this->sse('start', ['model' => 'echo']);
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $i => $char) {
            $this->sse('delta', ['text' => $char]);
            usleep(30000);
        }
        $this->sse('usage', ['in' => 0, 'out' => 0]);
        $this->sse('done', ['stop_reason' => 'end_turn']);
        exit();
    }

    /**
     * Инструменты, которые получает модель. Пустой список = модель отвечает
     * только текстом.
     *
     * Инструменты, а не свободный SQL: через них ИИ ходит тем же CRUD-путём,
     * что менеджер мышкой, значит права, триггеры и лог работают сами.
     *
     * ⚠️ Цикл инструментов пока только для протокола anthropic: блоки
     * tool_use/tool_result описаны в его формате, у OpenAI форма другая
     * (tool_calls / role: tool). На openai-подключении инструменты не даём —
     * лучше без данных, чем сломанный запрос.
     */
    protected function toolDefs()
    {
        if (empty($this->setting['allow_gtsapi'])) return [];
        if (strtolower($this->conn['protocol']) !== 'anthropic') return [];

        // ⚠️ array_merge, а НЕ «+»: у обоих списков числовые ключи с нуля, и
        // оператор + оставил бы левый элемент, молча выбросив инструмент записи.
        return array_merge([
            [
                'name'         => 'list_tables',
                'description'  => 'Список таблиц системы, доступных тебе и этому пользователю, с описанием. Начинай с него, если не знаешь, где лежат нужные данные.',
                'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
            ],
            [
                'name'         => 'table_config',
                'description'  => 'Конфиг таблицы как его видит интерфейс: поля с типами и подписями (у select — допустимые значения), доступные фильтры, действия и пометка, какие из них открыты тебе. Смотри сюда, прежде чем фильтровать или сортировать по незнакомому полю.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => ['table' => ['type' => 'string', 'description' => 'Имя таблицы из list_tables']],
                    'required'   => ['table'],
                ],
            ],
            [
                'name'         => 'read_rows',
                'description'  =>
                    'Прочитать строки таблицы. Запрос тот же, что шлёт интерфейс таблицы.' . "\n" .
                    'filters: {"поле": {"value": …, "matchMode": "equals"}}. matchMode: equals, notEquals, in, contains, notContains, startsWith, endsWith, lt, lte, gt, gte, dateIs, dateBefore, dateAfter. Короткая форма {"поле": значение} означает equals.' . "\n" .
                    'Несколько условий по одному полю: {"поле": {"operator": "and", "constraints": [{"value": …, "matchMode": "gte"}, …]}}.' . "\n" .
                    'Сортировка: sortField + sortOrder (1 по возрастанию, -1 по убыванию) или multiSortMeta: [{"field": …, "order": 1}, …].' . "\n" .
                    'Чтобы НАЙТИ запись по названию (изделие в каталоге, материал в справочнике), ставь action: "autocomplete" и query: "часть названия" — это тот же поиск, что в интерфейсе, он возвращает подходящие строки сразу. Перебирать каталог страницами не нужно.' . "\n" .
                    'В ответе есть total — сколько строк всего подходит под фильтр; если их больше лимита, бери следующую порцию через offset.' . "\n" .
                    'Проси только нужные поля через fields и небольшой лимит: выгрузка целиком стоит денег и забивает контекст. Лимит не больше 50.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'table'          => ['type' => 'string', 'description' => 'Имя таблицы из list_tables'],
                        'action'         => ['type' => 'string', 'description' => 'read (по умолчанию) или autocomplete — поиск по названию'],
                        'query'          => ['type' => 'string', 'description' => 'Что ищем — для action: autocomplete'],
                        'filters'        => ['type' => 'object', 'description' => 'Фильтры в формате таблицы (см. описание)'],
                        'sortField'      => ['type' => 'string', 'description' => 'Поле сортировки'],
                        'sortOrder'      => ['type' => 'integer', 'description' => '1 по возрастанию, -1 по убыванию'],
                        'multiSortMeta'  => [
                            'type'        => 'array',
                            'items'       => ['type' => 'object'],
                            'description' => 'Сортировка по нескольким полям: [{"field": …, "order": 1}]',
                        ],
                        'fields'         => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => 'Какие колонки вернуть. Пусто — все, но так дороже.',
                        ],
                        'limit'          => ['type' => 'integer', 'description' => 'Сколько строк, по умолчанию 20, максимум 50'],
                        'offset'         => ['type' => 'integer', 'description' => 'С какой строки начать'],
                    ],
                    'required'   => ['table'],
                ],
            ],
        ], $this->actionToolDefs(), $this->promptToolDefs(), $this->fileToolDefs(), $this->sqlToolDefs(), $this->writeToolDefs());
    }

    /**
     * Вызов действия таблицы. Это тот же контракт gtsAPI, что у людей:
     * table + api_action + параметры. Своей логики здесь нет - что можно,
     * решает конфиг таблицы, а не шлюз.
     *
     * Пускает ядро: действие должно быть объявлено в actions таблицы с
     * 'ai':1, а чтобы работать в чате без права записи - ещё и 'ai_read':1.
     */
    protected function actionToolDefs()
    {
        if (empty($this->setting['allow_gtsapi'])) return [];

        return [[
            'name'         => 'run_action',
            'description'  =>
                'Вызвать действие таблицы (то же, что делает интерфейс: table + action).' . "\n" .
                'Какие действия есть у таблицы и что они принимают - смотри в table_config, раздел actions.' . "\n" .
                'Пример: table=gsProduct, action=gtsshop/product_params, params={"product_ids":[629,1107]} - вернёт параметры изделий со списками допустимых значений одним запросом, вместо обхода справочников по одному.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'table'  => ['type' => 'string', 'description' => 'Таблица gtsAPI'],
                    'action' => ['type' => 'string', 'description' => 'Имя действия, например gtsshop/product_params'],
                    'params' => ['type' => 'object', 'description' => 'Параметры действия'],
                ],
                'required'   => ['table', 'action'],
            ],
        ]];
    }

    /** Выполнить действие таблицы и вернуть ответ как есть. */
    protected function runAction(array $input, array $scope = [])
    {
        $table  = isset($input['table']) ? (string)$input['table'] : '';
        $action = isset($input['action']) ? (string)$input['action'] : '';
        if ($table === '' || $action === '') return ['error' => 'Нужны table и action.'];

        $deny = $this->tableAllowed($table);
        if ($deny !== '') return ['error' => $deny];

        $params = isset($input['params']) && is_array($input['params']) ? $input['params'] : [];
        // Область работы задаёт сервер - так же, как в чтении и записи.
        foreach ($scope as $k => $v) $params[$k] = $v;

        $resp = $this->needBridge()->call($table, $action, $params);
        if (empty($resp['success'])) {
            return ['error' => isset($resp['message']) && $resp['message'] !== ''
                ? $resp['message'] : 'Действие не выполнено.'];
        }
        return isset($resp['data']) ? $resp['data'] : $resp;
    }

    /**
     * Инструмент выдачи файла. Модель готовит содержимое, сервер кладёт его в
     * хранилище чата и отдаёт ссылку на скачивание.
     *
     * Зачем: результат разбора заявки Олег забирает ФАЙЛОМ - листом «вставка»,
     * который копируется в расчётную форму. Пересказ таблицы в чате для этого
     * бесполезен, нужен именно файл.
     */
    protected function fileToolDefs()
    {
        return [[
            'name'         => 'save_file',
            'description'  =>
                'Сохранить файл и дать пользователю ссылку на скачивание.' . "\n" .
                'format: xlsx - таблица (передай rows: массив строк, каждая строка - массив значений; первая строка обычно заголовки), md/txt/csv - текст (передай text).' . "\n" .
                'Для разбора заявки собирай xlsx: заголовки первой строкой, дальше данные.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'name'   => ['type' => 'string', 'description' => 'Имя файла без расширения'],
                    'format' => ['type' => 'string', 'description' => 'xlsx, csv, md или txt'],
                    'text'   => ['type' => 'string', 'description' => 'Содержимое для md, txt и csv'],
                    'rows'   => [
                        'type'        => 'array',
                        'items'       => ['type' => 'array', 'items' => ['type' => 'string']],
                        'description' => 'Строки таблицы для xlsx',
                    ],
                    'sheet'  => ['type' => 'string', 'description' => 'Имя листа для xlsx, по умолчанию «вставка»'],
                ],
                'required'   => ['name', 'format'],
            ],
        ], [
            'name'         => 'attach_file',
            'description'  =>
                'Прикрепить последний загруженный в этом чате текстовый файл к настройке чата - он станет правилом.' . "\n" .
                'Так правила правят снаружи: выгрузил промпт файлом через save_file, поправил у себя, приложил обратно и прикрепил.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'name'        => ['type' => 'string', 'description' => 'Как назвать правило'],
                    'description' => ['type' => 'string', 'description' => 'Когда это правило нужно - одной строкой'],
                ],
                'required'   => ['name'],
            ],
        ]];
    }

    /**
     * Путь к файлу, выбранному в настройке.
     *
     * Поле хранит то, что отдаёт браузер файлов, - адрес вида
     * /assets/uploads/gtsapi/… Переводим его в путь на диске относительно
     * корня сайта. Если там просто имя - значит файл из хранилища чата
     * (так кладёт attach_file).
     */
    protected function settingFilePath($path)
    {
        $path = trim($path);
        if ($path === '') return '';

        // Полный адрес сайта - отрезаем его.
        $base = rtrim((string)$this->modx->getOption('site_url'), '/');
        if ($base !== '' && strpos($path, $base) === 0) $path = substr($path, strlen($base));

        if (strpos($path, '/') !== false) {
            $rel = ltrim($path, '/');
            // ⚠️ Никаких «..»: поле правит человек, но правит через форму,
            // и подниматься выше корня сайта ему незачем.
            if (strpos($rel, '..') !== false) return '';
            return MODX_BASE_PATH . $rel;
        }
        return $this->filesPath() . $path;
    }

    /**
     * Прикрепить последний загруженный в этом чате файл к настройке чата -
     * он станет правилом. Путь к файлу модель не видит и не должна: она
     * называет, ЧТО прикрепляем, а какой это файл, знает сервер.
     */
    protected function attachFile(array $input)
    {
        if (empty($this->setting['allow_prompt_edit'])) {
            return ['error' => 'Правка собственных промптов в этом чате запрещена настройкой.'];
        }
        $deny = $this->tableAllowed('gtsAISettingFile', true);
        if ($deny !== '') return ['error' => $deny];

        $name = trim((string)(isset($input['name']) ? $input['name'] : ''));
        if ($name === '') return ['error' => 'Нужно имя правила.'];

        $c = $this->modx->newQuery('gtsAIFile');
        $c->where(['user_id' => $this->modx->user->id]);
        if ($this->chat) $c->where(['chat_id' => $this->chat->id]);
        $c->sortby('id', 'DESC');
        $c->limit(1);
        $file = $this->modx->getObject('gtsAIFile', $c);
        if (!$file) return ['error' => 'В этом чате нет загруженного файла.'];

        $ext = strtolower(pathinfo((string)$file->get('name'), PATHINFO_EXTENSION));
        if (!in_array($ext, $this->textExtensions(), true)) {
            return ['error' => 'Правилом может стать только текстовый файл (' . implode(', ', $this->textExtensions()) . '), а это ' . $ext . '.'];
        }

        $row = $this->modx->getObject('gtsAISettingFile', [
            'setting_id' => (int)$this->setting['id'],
            'name'       => $name,
        ]);
        if (!$row) {
            $row = $this->modx->newObject('gtsAISettingFile', [
                'setting_id' => (int)$this->setting['id'],
                'name'       => $name,
                'createdon'  => date('Y-m-d H:i:s'),
                'active'     => 1,
                'rank'       => 0,
            ]);
        }
        $row->set('description', trim((string)(isset($input['description']) ? $input['description'] : '')));
        $row->set('path', $file->get('path'));
        $row->set('size', (int)$file->get('size'));
        if (!$row->save()) return ['error' => 'Не удалось прикрепить файл.'];

        return [
            'attached' => $name,
            'file'     => $file->get('name'),
            'note'     => 'Файл стал правилом настройки «' . $this->setting['name'] . '»: его текст будет подгружаться по имени.',
        ];
    }

    /** Сохранить файл и вернуть ссылку. */
    protected function saveFile(array $input)
    {
        $format = strtolower(trim((string)(isset($input['format']) ? $input['format'] : '')));
        $known  = ['xlsx', 'csv', 'md', 'txt'];
        if (!in_array($format, $known, true)) {
            return ['error' => 'Формат должен быть одним из: ' . implode(', ', $known) . '.'];
        }

        $base = trim((string)(isset($input['name']) ? $input['name'] : ''));
        $base = preg_replace('~[\\\\/:*?"<>|]+~u', '_', $base);
        $base = trim((string)$base, " .");
        if ($base === '') $base = 'файл';
        $base = mb_substr($base, 0, 80);

        $stored = date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . $format;
        $full   = $this->filesPath() . $stored;

        if ($format === 'xlsx') {
            $rows = isset($input['rows']) && is_array($input['rows']) ? $input['rows'] : [];
            if (!$rows) return ['error' => 'Для xlsx нужны rows - строки таблицы.'];
            $err = $this->writeSheet($full, $rows, isset($input['sheet']) ? (string)$input['sheet'] : '');
            if ($err !== '') return ['error' => $err];
        } else {
            $text = isset($input['text']) ? (string)$input['text'] : '';
            if ($text === '') return ['error' => 'Для ' . $format . ' нужен text.'];
            if (strlen($text) > 2 * 1024 * 1024) return ['error' => 'Файл больше 2 МБ - слишком много.'];
            // ⚠️ BOM у csv: без него Excel на Windows открывает кириллицу кракозябрами.
            if ($format === 'csv') $text = "\xEF\xBB\xBF" . $text;
            if (@file_put_contents($full, $text) === false) {
                return ['error' => 'Не удалось сохранить файл.'];
            }
        }

        $name = $base . '.' . $format;
        $row  = $this->modx->newObject('gtsAIFile', [
            'chat_id'   => $this->chat ? $this->chat->id : 0,
            'user_id'   => $this->modx->user->id,
            'name'      => $name,
            'path'      => $stored,
            'size'      => (int)@filesize($full),
            'mime'      => $format === 'xlsx'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : ($format === 'csv' ? 'text/csv' : 'text/markdown'),
            'createdon' => date('Y-m-d H:i:s'),
        ]);
        $row->save();

        $url = $this->modx->getOption('site_url') . 'gtsai/file/' . (int)$row->id;
        $this->sse('file', ['id' => (int)$row->id, 'name' => $name, 'url' => $url, 'size' => (int)$row->get('size')]);

        return [
            'saved' => $name,
            'url'   => $url,
            'note'  => 'Ссылка уже показана пользователю в чате - повторять её текстом не нужно.',
        ];
    }

    /**
     * Число ли это с точки зрения расчётной формы.
     *
     * Запятая как разделитель - наш обычный ввод («0,7»), её переводим в точку.
     * А вот ведущий ноль («007»), длинные цифровые коды и всё, где есть буквы
     * или знаки, остаются текстом: это артикулы и обозначения, Excel иначе
     * съест ноль или покажет их экспонентой.
     */
    protected function looksNumeric($v)
    {
        $v = trim((string)$v);
        if ($v === '') return false;
        if (!preg_match('~^-?\d{1,15}([.,]\d+)?$~', str_replace(' ', '', $v))) return false;

        $digits = ltrim(str_replace(['-', ' '], '', $v), '');
        if (strlen($digits) > 1 && $digits[0] === '0' && $digits[1] !== '.' && $digits[1] !== ',') return false;
        return true;
    }

    /** Запись таблицы в xlsx. Библиотека PHPExcel уже есть в системе. */
    protected function writeSheet($full, array $rows, $sheetName = '')
    {
        $path = gtsAPI::phpExcelPath($this->modx);
        if (!$path) return 'На сайте нет библиотеки PHPExcel - xlsx собрать нечем.';
        require_once $path . 'PHPExcel.php';

        if (count($rows) > 5000) return 'Больше 5000 строк за раз нельзя.';

        try {
            $book = new PHPExcel();
            $sheet = $book->getActiveSheet();
            $sheet->setTitle($sheetName !== '' ? mb_substr($sheetName, 0, 31) : 'вставка');

            $r = 1;
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $c = 0;
                foreach ($row as $v) {
                    $v = (string)$v;
                    if ($this->looksNumeric($v)) {
                        // Числом, а не текстом: этот файл вставляют в расчётную
                        // форму, и текстовые «300» или «0,7» там не посчитаются.
                        $sheet->setCellValueByColumnAndRow($c, $r, (float)str_replace([' ', ','], ['', '.'], $v));
                    } else {
                        $sheet->setCellValueExplicitByColumnAndRow($c, $r, $v, PHPExcel_Cell_DataType::TYPE_STRING);
                    }
                    $c++;
                }
                $r++;
            }
            $writer = PHPExcel_IOFactory::createWriter($book, 'Excel2007');
            $writer->save($full);
        } catch (\Throwable $e) {
            return 'Не удалось собрать xlsx: ' . $e->getMessage();
        }
        return '';
    }

    /**
     * Инструмент SQL — ТОЛЬКО чтение и только отдельным пользователем базы.
     *
     * Почему не фильтр по словам в запросе: его обходят — точкой с запятой,
     * комментарием, `SELECT … INTO OUTFILE`. Единственная честная защита —
     * пользователь MySQL, у которого есть лишь SELECT; проверки ниже
     * дополняют её, а не заменяют.
     */
    protected function sqlToolDefs()
    {
        if (empty($this->setting['allow_sql'])) return [];
        $creds = $this->sqlCreds();
        if ($creds['user'] === '' || $creds['password'] === '') return [];

        return [[
            'name'         => 'sql_query',
            'description'  =>
                'Выполнить SELECT к базе и получить строки. Только чтение: соединение идёт под пользователем с правом SELECT, любые изменения отклоняются базой.' . "\n" .
                'Пиши один запрос без точки с запятой. Если LIMIT не указан, он будет добавлен.' . "\n" .
                'Это запасной путь для того, чего не достать инструментами таблиц (сводные цифры, группировки). Для обычных данных пользуйся read_rows: там работают права, а тут их нет.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'SELECT-запрос'],
                    'limit' => ['type' => 'integer', 'description' => 'Сколько строк вернуть, по умолчанию 50, максимум 200'],
                ],
                'required'   => ['query'],
            ],
        ]];
    }

    /**
     * Доступы читающего пользователя.
     *
     * ⚠️ Читаем НАПРЯМУЮ из таблицы настроек, а не через getOption: настройки
     * заводит SQL-скрипт из README, минуя MODX, и кэш о них ещё не знает.
     * Иначе после скрипта пришлось бы отдельно чистить кэш, а смысл скрипта
     * ровно в том, чтобы ничего больше руками не делать.
     *
     * Адрес базы по умолчанию — тот же, которым ходит сам сайт. Это снимает
     * самую частую ошибку: на OSPanel база доступна по имени модуля
     * (`mysql:host=mysql-5.7`), а не по `localhost`. Настройка `gtsai_sql_dsn`
     * нужна, только если читать надо другую базу или другой сервер.
     */
    protected function sqlCreds()
    {
        if ($this->sqlCreds !== null) return $this->sqlCreds;

        $read = function ($key) {
            $o = $this->modx->getObject('modSystemSetting', ['key' => $key]);
            return $o ? trim((string)$o->get('value')) : '';
        };

        $dsn = $read('gtsai_sql_dsn');
        if ($dsn === '') $dsn = (string)$this->modx->config['dsn'];

        $this->sqlCreds = [
            'dsn'      => $dsn,
            'user'     => $read('gtsai_sql_user'),
            'password' => $read('gtsai_sql_password'),
        ];
        return $this->sqlCreds;
    }

    /** Соединение только на чтение. Создаётся при первом запросе. */
    protected function sqlConnect()
    {
        if ($this->sqlPdo !== null) return $this->sqlPdo;

        $creds = $this->sqlCreds();
        $dsn  = $creds['dsn'];
        $user = $creds['user'];
        $pass = $creds['password'];
        if ($dsn === '' || $user === '' || $pass === '') return null;

        try {
            $this->sqlPdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 10,
            ]);
        } catch (\Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[gtsAI] соединение для SQL не поднялось: ' . $e->getMessage());
            return null;
        }
        return $this->sqlPdo;
    }

    /** Выполнить запрос на чтение. */
    protected function runSql(array $input)
    {
        if (empty($this->setting['allow_sql'])) {
            return ['error' => 'SQL для этого чата не разрешён.'];
        }
        $pdo = $this->sqlConnect();
        if (!$pdo) return ['error' => 'Доступ к базе на чтение не настроен: нет читающего пользователя (см. README пакета gtsAI).'];

        $query = isset($input['query']) ? trim((string)$input['query']) : '';
        if ($query === '') return ['error' => 'Пустой запрос.'];

        // Один запрос за раз: точка с запятой в середине — путь протащить второй.
        if (strpos(rtrim($query, "; \t\n\r"), ';') !== false) {
            return ['error' => 'Только один запрос без точки с запятой.'];
        }
        if (!preg_match('~^\s*(select|with|show|explain|describe|desc)\s~i', $query)) {
            return ['error' => 'Разрешены только SELECT, WITH, SHOW, EXPLAIN и DESCRIBE.'];
        }
        if (preg_match('~\b(into\s+outfile|into\s+dumpfile|load_file|sleep\s*\()~i', $query)) {
            return ['error' => 'В запросе есть запрещённая конструкция.'];
        }

        // Таблицы, которых не касаемся даже на чтение.
        $deny = array_filter(array_map('trim', explode(',', (string)$this->modx->getOption('gtsai_sql_deny', null, ''))));
        foreach ($deny as $t) {
            if ($t !== '' && stripos($query, $t) !== false) {
                return ['error' => 'К таблице ' . $t . ' обращаться нельзя.'];
            }
        }

        $limit = isset($input['limit']) ? (int)$input['limit'] : 50;
        $limit = max(1, min($limit, 200));
        // Лимит дописываем только выборкам: SHOW и DESCRIBE его не принимают
        // и падают синтаксической ошибкой.
        if (preg_match('~^\s*(select|with)\s~i', $query) && !preg_match('~\blimit\s+\d~i', $query)) {
            $query .= ' LIMIT ' . $limit;
        }

        try {
            $stmt = $pdo->query($query);
            $rows = $stmt->fetchAll();
        } catch (\Throwable $e) {
            return ['error' => 'База отказала: ' . $e->getMessage()];
        }

        return ['rows' => $rows, 'count' => count($rows)];
    }

    /**
     * Инструмент записи. Отдаётся модели только если запись разрешена
     * настройкой чата — иначе его в списке просто нет, и модель не обещает
     * пользователю того, чего не сможет.
     */
    protected function writeToolDefs()
    {
        if (empty($this->setting['allow_write'])) return [];

        $tail = $this->askConfirm
            ? ' Изменения НЕ применяются сразу: пользователь увидит, что было и что станет, и нажмёт кнопку.'
            : ' Изменения применяются сразу, у пользователя останется кнопка откатить.';

        return [[
            'name'         => 'write_rows',
            'description'  =>
                'Записать строки через действие таблицы.' . "\n" .
                'action — имя действия из table_config, у которого стоит ai: true (например update, create, insert). Никаких особых имён тут нет: это те же действия, что у таблицы в интерфейсе.' . "\n" .
                'rows — массив запросов в том же виде, что шлёт интерфейс: для update обязателен id и изменяемые поля, для create — поля новой строки.' . "\n" .
                'Область работы (raschet_id и подобное) подставляет сервер — не передавай её сам.' . $tail,
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'table'  => ['type' => 'string', 'description' => 'Имя таблицы из list_tables'],
                    'action' => ['type' => 'string', 'description' => 'Действие таблицы: update, create, insert…'],
                    'rows'   => [
                        'type'        => 'array',
                        'items'       => ['type' => 'object'],
                        'description' => 'Строки одним массивом: все сразу, а не по одной за вызов. До 100 за раз',
                    ],
                    'reason' => ['type' => 'string', 'description' => 'Зачем правка — попадёт в чат пользователю'],
                ],
                'required'   => ['table', 'action', 'rows'],
            ],
        ]];
    }

    /**
     * Мост к gtsAPI. $rollback отдельным флагом: откат делает сам шлюз по
     * кнопке пользователя, модели это действие не достаётся.
     */
    protected function needBridge($rollback = false)
    {
        require_once $this->config['classesPath'] . 'bridge.class.php';
        if (!$this->bridge) {
            $this->bridge = new gtsAIBridge($this->modx, [
                'write'    => !empty($this->setting['allow_write']),
                'rollback' => $rollback,
            ]);
        } elseif ($rollback) {
            $this->bridge->allowRollback();
        }
        return $this->bridge;
    }

    /** Выполнить инструмент. Возвращает то, что уйдёт модели как tool_result. */
    protected function runTool($name, array $input, array $scope)
    {
        $this->needBridge();

        switch ($name) {
            case 'list_tables':
                return ['tables' => $this->bridge->listTables()];

            case 'table_config':
                if (empty($input['table'])) return ['error' => 'Не указана таблица.'];
                return $this->bridge->tableConfig((string)$input['table']);

            case 'read_rows':
                if (empty($input['table'])) return ['error' => 'Не указана таблица.'];
                $table  = (string)$input['table'];
                $fields = isset($input['fields']) && is_array($input['fields']) ? $input['fields'] : [];
                // Всё, что понимает таблица, отдаём ей как есть: фильтры с
                // matchMode, сортировку, лимит и сдвиг. Свой разбор параметров
                // тут только мешал бы — контракт уже описан в gtsAPI.
                $request = array_intersect_key($input, array_flip([
                    'filters', 'sortField', 'sortOrder', 'multiSortMeta', 'limit', 'offset', 'ids', 'query',
                ]));
                $deny = $this->tableAllowed(isset($input['table']) ? $input['table'] : '');
                if ($deny !== '') return ['error' => $deny];
                $readAction = isset($input['action']) ? (string)$input['action'] : 'read';
                return $this->bridge->read($table, $request, $scope, $fields, $readAction);

            case 'run_action':
                return $this->capResult($this->runAction($input, $scope));

            case 'save_file':
                return $this->saveFile($input);

            case 'attach_file':
                return $this->attachFile($input);

            case 'read_prompt':
                return $this->readPrompt($input);

            case 'sql_query':
                return $this->runSql($input);

            case 'write_rows':
                return $this->toolWrite($input, $scope);
        }
        return ['error' => 'Неизвестный инструмент ' . $name];
    }

    /**
     * Запись. Два режима, оба оставляют пользователю выход:
     *   confirm_write = 1 — готовим дифф, показываем в чате и ждём кнопку.
     *                       Модель при этом ничего не меняет.
     *   confirm_write = 0 — пишем сразу, в чате остаётся кнопка откатить
     *                       (по batch_id, одним жестом на все строки).
     *
     * Что именно можно писать, решает конфиг таблицы: действие должно быть
     * помечено 'ai': 1. Проверяет это gtsAPI, здесь мы только собираем запрос.
     */
    protected function toolWrite(array $input, array $scope)
    {
        if (empty($this->setting['allow_write'])) {
            return ['error' => 'Запись запрещена настройкой этого чата.'];
        }
        $table  = isset($input['table']) ? (string)$input['table'] : '';
        $action = isset($input['action']) ? (string)$input['action'] : '';
        $rows   = isset($input['rows']) && is_array($input['rows']) ? $input['rows'] : [];
        $reason = isset($input['reason']) ? (string)$input['reason'] : '';

        if ($table === '' || $action === '' || !$rows) {
            return ['error' => 'Нужны table, action и rows.'];
        }
        $deny = $this->tableAllowed($table, true);
        if ($deny !== '') return ['error' => $deny];
        if (count($rows) > 100) {
            return ['error' => 'Больше 100 строк за раз нельзя. Раздели на части.'];
        }

        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            // batch_id ставит сервер; область работы — тоже, её модель не задаёт.
            unset($row['batch_id'], $row['raschet_id'], $row['order_id'], $row['smena_id']);
            if ($row) $clean[] = $row;
        }
        if (!$clean) return ['error' => 'Пустой список изменений.'];

        // Дифф для правки: показать человеку, что меняется. Для создания
        // показывать «было» нечего — показываем сами поля.
        $diff = [];
        foreach ($clean as $row) {
            if ($action === 'update') {
                $id = isset($row['id']) ? (int)$row['id'] : 0;
                if (!$id) return ['error' => 'Для update в каждой строке нужен id.'];
                $fields = array_diff_key($row, ['id' => 1]);
                $snap = $this->bridge->rowSnapshot($table, $id, array_keys($fields), $scope);
                if ($snap === null) {
                    return ['error' => "Строка {$id} таблицы {$table} недоступна — правка отменена целиком."];
                }
                $was = [];
                foreach ($fields as $f => $v) $was[$f] = isset($snap[$f]) ? $snap[$f] : null;
                $diff[] = ['id' => $id, 'was' => $was, 'will' => $fields];
            } else {
                $diff[] = ['id' => null, 'was' => [], 'will' => $row];
            }
        }

        if ($this->askConfirm) {
            $pendingId = $this->savePending($table, $action, $clean, $reason, $diff, $scope);
            if (!$pendingId) {
                return ['error' => 'Не удалось сохранить правку на подтверждение: нужна включённая история чата.'];
            }
            $this->sse('confirm', [
                'pending_id' => $pendingId,
                'table'      => $table,
                'action'     => $action,
                'reason'     => $reason,
                'diff'       => $diff,
            ]);
            return [
                'status' => 'ожидает подтверждения пользователя',
                'note'   => 'Изменения показаны пользователю, но НЕ применены. Скажи, что ждёшь нажатия кнопки, и не утверждай, что правка сделана.',
                'rows'   => count($clean),
            ];
        }

        $applied = $this->applyWrite($table, $action, $clean, $scope, $diff);
        if (!empty($applied['batch_id'])) $this->turnBatch = $applied['batch_id'];
        $this->sse('applied', $applied);
        return $applied;
    }

    /**
     * Собственно запись: N обычных вызовов действия с одним batch_id.
     *
     * Почему N штатных вызовов, а не один свой экшен: в логе появляются N
     * записей с корректными object_id и data_before, и существующий
     * restore_version откатывает правки построчно — своего кода для отката
     * писать не нужно. Созданные строки откатываются удалением (см. ниже).
     */
    protected function applyWrite($table, $action, array $rows, array $scope = [], array $diff = [])
    {
        $batchId = $this->newBatchId();
        $ok = [];
        $errors = [];

        foreach ($rows as $row) {
            $resp = $this->bridge->writeRow($table, $action, $row, $batchId, $scope);
            if (!empty($resp['success'])) {
                // У create идентификатор появляется только в ответе.
                $id = isset($row['id']) ? (int)$row['id'] : 0;
                if (!$id) {
                    $data = isset($resp['data']) ? $resp['data'] : [];
                    if (isset($data['object']['id'])) $id = (int)$data['object']['id'];
                    elseif (isset($data['id']))       $id = (int)$data['id'];
                }
                $ok[] = $id;
            } else {
                $errors[] = [
                    'row'     => isset($row['id']) ? (int)$row['id'] : null,
                    'message' => isset($resp['message']) ? $resp['message'] : 'ошибка',
                ];
            }
        }

        // Часть строк не прошла — не оставляем половину применённой.
        if ($errors && $ok) {
            $this->rollbackBatch($batchId, $table);
            return [
                'success'     => false,
                'rolled_back' => true,
                'message'     => 'Часть строк не прошла, поэтому пакет откатан целиком.',
                'errors'      => $errors,
            ];
        }

        // Не прошло вообще ничего — показываем причину, а не общее «не удалось».
        if ($errors && !$ok) {
            return [
                'success' => false,
                'message' => 'Ни одна строка не прошла: ' . $errors[0]['message'],
                'errors'  => $errors,
            ];
        }

        return [
            'success'  => empty($errors),
            'batch_id' => $batchId,
            'table'    => $table,
            'action'   => $action,
            'updated'  => $ok,
            'errors'   => $errors,
            'diff'     => $diff,
        ];
    }

    protected function rollbackBatch($batchId, $table = '')
    {
        $this->needBridge(true);

        $done = 0;
        $failed = 0;
        $problems = [];

        foreach ($this->bridge->batchLog($batchId) as $log) {
            if ($table !== '' && $log['log_table'] !== $table) continue;

            $before = $log['data_before'] ? json_decode($log['data_before'], true) : null;
            $after  = $log['data_after'] ? json_decode($log['data_after'], true) : null;

            // Создание вернуть «как было» нечем — удаляем ровно ту строку,
            // которую этот же пакет и создал.
            if ($log['log_action'] === 'create') {
                $resp = $this->bridge->deleteRow($log['log_table'], (int)$log['object_id']);
                if (!empty($resp['success'])) $done++; else {
                    $failed++;
                    $problems[] = ['id' => (int)$log['object_id'], 'message' => 'созданную строку не удалось удалить'];
                }
                continue;
            }

            $resp = $this->bridge->restoreVersion($log['log_table'], $log['id']);
            if (empty($resp['success'])) {
                $failed++;
                $problems[] = [
                    'id'      => (int)$log['object_id'],
                    'message' => isset($resp['message']) ? $resp['message'] : 'откат не выполнен',
                ];
                continue;
            }

            $bad = $this->restoreMismatch($log['log_table'], (int)$log['object_id'], $before, $after);
            if ($bad) {
                $failed++;
                $problems[] = ['id' => (int)$log['object_id'], 'fields' => $bad];
            } else {
                $done++;
            }
        }

        $out = ['restored' => $done, 'failed' => $failed];
        if ($problems) $out['problems'] = $problems;
        return $out;
    }

    /**
     * Проверка отката: какие из изменённых полей так и не вернулись.
     * Сравниваем только те поля, которые правка реально меняла.
     */
    protected function restoreMismatch($table, $id, $before, $after)
    {
        if (!is_array($before) || !is_array($after)) return [];

        $changed = [];
        foreach ($after as $field => $value) {
            $old = array_key_exists($field, $before) ? $before[$field] : null;
            if ((string)$old !== (string)$value) $changed[] = $field;
        }
        if (!$changed) return [];

        // Область работы берём из самого снимка: без неё строки расчёта не
        // читаются вовсе, и проверка отката сообщала бы о провале там, где
        // значение на самом деле вернулось.
        $scope = [];
        foreach (['raschet_id', 'order_id', 'smena_id'] as $key) {
            if (!empty($before[$key])) $scope[$key] = $before[$key];
        }

        $row = $this->bridge->rowSnapshot($table, $id, $changed, $scope);
        if ($row === null) return $changed;

        $bad = [];
        foreach ($changed as $field) {
            $want = array_key_exists($field, $before) ? $before[$field] : null;
            $now  = array_key_exists($field, $row) ? $row[$field] : null;
            // NULL и пустая строка здесь одно и то же: строковые поля
            // восстанавливаются как '', потому что NULL update не принимает.
            if ((string)$want !== (string)$now) {
                $bad[] = $field . ': ожидали ' . var_export($want, true) . ', в базе ' . var_export($now, true);
            }
        }
        return $bad;
    }

    protected function newBatchId()
    {
        // Уникальности в пределах лога достаточно, криптостойкость тут ни к чему.
        return 'ai' . date('YmdHis') . substr(md5(uniqid('', true)), 0, 8);
    }

    /** Правка, ожидающая подтверждения, живёт сообщением чата. */
    protected function savePending($table, $action, array $rows, $reason, array $diff, array $scope = [])
    {
        if (!$this->chat) return 0;

        $msg = $this->modx->newObject('gtsAIMessage', [
            'chat_id'   => $this->chat->id,
            'role'      => 'pending',
            'content'   => $reason,
            'blocks'    => json_encode([
                'table'  => $table,
                'action' => $action,
                'rows'   => $rows,
                'diff'   => $diff,
                // ⚠️ Область работы храним вместе с заявкой. Кнопка «Применить»
                // шлёт только её номер и контекста страницы не несёт — без
                // сохранённой области строки создавались бы с raschet_id = 0.
                'scope'  => $scope,
            ], JSON_UNESCAPED_UNICODE),
            'createdon' => date('Y-m-d H:i:s'),
        ]);
        return $msg->save() ? (int)$msg->id : 0;
    }

    /**
     * Ответ ИИ стримом, с циклом инструментов.
     *
     * Цикл: стрим → если модель позвала инструменты, выполняем их, отдаём
     * результаты и стримим дальше. Ограничители — max_iterations и max_seconds
     * из настройки: без них зацикленная модель сожгла бы бюджет за ночь.
     */
    protected function actionMessage(array $request)
    {
        $resp = $this->loadSetting(isset($request['setting']) ? $request['setting'] : '');
        if (!$resp['success']) {
            $this->sseStart();
            $this->sse('error', ['message' => $resp['message']]);
            exit();
        }

        $text = isset($request['text']) ? trim((string)$request['text']) : '';
        if ($text === '') {
            $this->sseStart();
            $this->sse('error', ['message' => 'Пустое сообщение.']);
            exit();
        }

        $this->pickModel($request);
        $this->pickConfirm($request);

        $fileIds = isset($request['file_ids']) && is_array($request['file_ids'])
            ? array_map('intval', $request['file_ids']) : [];

        $chat       = $this->resolveChat($request, $text);
        $this->chat = $chat;
        // Историю собираем ДО записи вопроса, иначе он уедет в запрос дважды.
        $messages   = $this->buildMessages($chat, $text, $fileIds);
        // А вот записываем сразу: иначе заявка на подтверждение (она пишется
        // внутри цикла) встаёт в истории ВЫШЕ вопроса, а при обрыве ответа
        // вопрос пропадает совсем.
        $this->saveMessage($chat, 'user', $text, 0, $fileIds);
        $this->bindFiles($chat, $fileIds);

        $provider = gtsAIProvider::factory($this->conn);
        if (!$provider) {
            $this->sseStart();
            $this->sse('error', ['message' => 'Неизвестный протокол подключения: ' . $this->conn['protocol']]);
            exit();
        }

        $this->sseStart();
        $this->sse('start', [
            'model'   => $this->conn['model'],
            'chat_id' => $chat ? (int)$chat->id : 0,
        ]);

        // Обрыв на середине цикла инструментов (закрыли вкладку, когда две
        // правки из пяти прошли) не должен оставлять половину применённой:
        // откатываем пакет целиком. На штатном финале turnDone = true и этот
        // обработчик ничего не делает.
        register_shutdown_function(function () {
            if ($this->turnDone || $this->turnBatch === '') return;
            $out = $this->rollbackBatch($this->turnBatch);
            $this->modx->log(modX::LOG_LEVEL_ERROR,
                '[gtsAI] ответ прерван, пакет ' . $this->turnBatch . ' откатан: '
                . 'восстановлено ' . $out['restored'] . ', не удалось ' . $out['failed']);
        });

        $started  = microtime(true);
        $deadline = $started + max(10, (int)$this->setting['max_seconds']);
        $maxIter  = max(1, (int)$this->setting['max_iterations']);
        $toolDefs = $this->toolDefs();
        // Область работы задаёт сервер из GET страницы, модель её не заполняет.
        $scope    = $this->scopeFromRequest($request);

        $answerAll   = '';
        $usedTools   = [];
        $failed      = '';
        $stop        = '';
        $emptyAnswer = false;
        $maxTokens   = (int)(isset($this->setting['max_tokens']) ? $this->setting['max_tokens'] : 0);
        if ($maxTokens < 1024) $maxTokens = 32000;
        $iteration = 0;
        // Всё, что добавится в $messages дальше, - шаги этого хода: пары
        // «запрос инструмента - результат». Их и сохраним вместе с ответом.
        $turnStart = count($messages);

        for ($iteration = 1; $iteration <= $maxIter; $iteration++) {
            $turnText = '';
            $calls    = [];

            $provider->stream([
                'model'      => $this->conn['model'],
                'system'     => $this->systemPrompt($request, $scope),
                'messages'   => $messages,
                'tools'      => $toolDefs,
                // Потолок длины одного ответа. Это не предел модели (opus-5-5
                // принимает и 64000), а деньги: выход дорогой, и один
                // разогнавшийся ответ способен съесть дневной лимит чата.
                // Поэтому настройкой на чат, с запасом по умолчанию.
                'max_tokens' => $maxTokens,
            ], function ($ev) use (&$turnText, &$calls, &$stop, &$failed) {
                switch ($ev['type']) {
                    case 'delta':
                        $turnText .= $ev['text'];
                        $this->sse('delta', ['text' => $ev['text']]);
                        break;
                    case 'usage':
                        $this->sse('usage', $ev);
                        break;
                    case 'tool':
                        $calls[] = $ev;
                        break;
                    case 'stop_reason':
                        $stop = $ev['stop_reason'];
                        break;
                    case 'error':
                        $failed = $ev['message'];
                        $this->sse('error', ['message' => $ev['message']]);
                        break;
                }
            });

            $answerAll .= $turnText;
            if ($failed || !$calls) break;

            // Ответ модели возвращаем ей же обратно: текст + запросы инструментов.
            $assistant = [];
            if ($turnText !== '') $assistant[] = ['type' => 'text', 'text' => $turnText];
            foreach ($calls as $call) {
                $assistant[] = [
                    'type'  => 'tool_use',
                    'id'    => $call['id'],
                    'name'  => $call['name'],
                    'input' => $call['input'] ? $call['input'] : new stdClass(),
                ];
            }
            $messages[] = ['role' => 'assistant', 'content' => $assistant];

            $results = [];
            foreach ($calls as $call) {
                $usedTools[] = $call['name'];
                $this->sse('tool', ['name' => $call['name'], 'input' => $call['input']]);

                $result = !empty($call['broken'])
                    ? ['error' => 'Аргументы вызова оборвались: ответ упёрся в предел длины. '
                        . 'Повтори вызов с более коротким содержимым (например, разбей файл на части).']
                    : $this->runTool($call['name'], (array)$call['input'], $scope);
                $result = $this->capResult($result);
                $results[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $call['id'],
                    'content'     => json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
            }
            $messages[] = ['role' => 'user', 'content' => $results];

            if (microtime(true) > $deadline) {
                $note = "\n\n_Остановился по времени: ответ занял больше " . (int)$this->setting['max_seconds'] . " с._";
                $this->sse('delta', ['text' => $note]);
                $answerAll .= $note;
                break;
            }
        }

        if ($iteration > $maxIter && $maxIter > 0) {
            $note = "\n\n_Остановился после " . $maxIter . " шагов с инструментами._";
            $this->sse('delta', ['text' => $note]);
            $answerAll .= $note;
        }

        // Пустой ответ выглядит как зависший чат: видны вызовы инструментов, а
        // под ними ничего. Так бывает, когда модель упёрлась в предел длины
        // ответа посреди вызова - текста она написать не успела, а вызов не
        // доехал. Объясняем это прямо в чате, а не оставляем пустой пузырь.
        if (trim($answerAll) === '' && !$failed) {
            $note = $stop === 'max_tokens'
                ? '_Ответ оборвался: модель упёрлась в предел длины одного ответа ('
                    . $maxTokens . ' токенов). Попросите её работать по шагам или поднимите '
                    . '«Макс. токенов в ответе» в настройке чата._'
                : '_Модель не прислала ответ' . ($stop !== '' ? ' (остановка: ' . $stop . ')' : '') . '. Повторите запрос._';
            $this->sse('delta', ['text' => $note]);
            $answerAll .= $note;
            if ($failed === '') $failed = 'пустой ответ, остановка: ' . ($stop !== '' ? $stop : 'неизвестно');
            $emptyAnswer = true;
        }

        $usage = $provider->getUsage();
        $this->saveMessage(
            $chat,
            'assistant',
            $answerAll,
            isset($usage['out']) ? (int)$usage['out'] : 0,
            [],
            array_slice($messages, $turnStart)
        );
        $this->writeLog(
            $chat, $usage, $started,
            ($failed !== '' && empty($emptyAnswer)) ? 'error' : 'ok',
            $failed, $usedTools, min($iteration, $maxIter)
        );

        $this->turnDone = true;
        $this->sse('done', ['stop_reason' => $stop, 'chat_id' => $chat ? (int)$chat->id : 0]);
        exit();
    }

    /**
     * Список моделей подключения.
     *
     * Ответ кладём в кэш на час: список меняется редко, а дёргать провайдера
     * при каждом открытии чата незачем.
     */
    protected function actionModels(array $request)
    {
        $resp = $this->loadSetting(isset($request['setting']) ? $request['setting'] : '');
        if (!$resp['success']) return $resp;

        $key   = 'models_' . (int)$this->conn['id'];
        $cache = $this->modx->cacheManager->get($key, ['cache_prefix' => 'gtsai/']);
        if (!empty($request['refresh'])) $cache = null;

        if (!is_array($cache)) {
            $provider = gtsAIProvider::factory($this->conn);
            if (!$provider) return $this->error('Неизвестный протокол подключения.');

            $list = $provider->models();
            if (!$list['success']) return $this->error($list['message']);

            $cache = $list['models'];
            $this->modx->cacheManager->set($key, $cache, 3600, ['cache_prefix' => 'gtsai/']);
        }

        return $this->success('', ['data' => [
            'current' => $this->conn['model'],
            'models'  => $cache,
        ]]);
    }

    /**
     * Спрашивать ли подтверждение перед записью.
     *
     * Настройка чата задаёт значение по умолчанию, человек может переключить
     * его прямо в чате — правки всё равно идут под его правами, пишутся в лог
     * и откатываются кнопкой. Запереть переключатель можно флагом
     * confirm_lock: там, где правки важные, решение остаётся за настройкой.
     */
    protected function pickConfirm(array $request)
    {
        $this->askConfirm = !empty($this->setting['confirm_write']);
        if (!empty($this->setting['confirm_lock'])) return;
        if (array_key_exists('confirm', $request)) {
            $this->askConfirm = !empty($request['confirm']);
        }
    }

    /**
     * Модель этого сообщения.
     *
     * Выбор приходит от пользователя, поэтому сверяем его со списком
     * провайдера: в запрос должна уходить существующая модель, а не любая
     * строка из браузера.
     */
    protected function pickModel(array $request)
    {
        $want = isset($request['model']) ? trim((string)$request['model']) : '';
        if ($want === '' || $want === $this->conn['model']) return;

        $cache = $this->modx->cacheManager->get('models_' . (int)$this->conn['id'], ['cache_prefix' => 'gtsai/']);
        if (!is_array($cache)) {
            $provider = gtsAIProvider::factory($this->conn);
            $list = $provider ? $provider->models() : ['success' => false];
            if (empty($list['success'])) return;
            $cache = $list['models'];
            $this->modx->cacheManager->set('models_' . (int)$this->conn['id'], $cache, 3600, ['cache_prefix' => 'gtsai/']);
        }

        foreach ($cache as $m) {
            if ($m['id'] === $want) { $this->conn['model'] = $want; return; }
        }
    }

    /**
     * Куда кладём файлы чата.
     *
     * В core, а НЕ в assets: папка компонента в assets перезаписывается при
     * установке пакета, и загруженное пропало бы при ближайшей выкладке.
     * Плюс сюда нет прямого доступа из браузера — файлы отдаёт только шлюз.
     */
    protected function filesPath()
    {
        $path = MODX_CORE_PATH . 'gtsai_files/';
        if (!is_dir($path)) @mkdir($path, 0775, true);
        return $path;
    }

    /**
     * Что принимаем.
     *
     * Картинки и PDF модель читает нативно. Таблицы (Excel, CSV) — нет: это
     * zip с XML, его разбираем на сервере и отдаём модели текстом.
     */
    protected function allowedMime()
    {
        return [
            'image/png'       => 'png',
            'image/jpeg'      => 'jpg',
            'image/webp'      => 'webp',
            'image/gif'       => 'gif',
            'application/pdf' => 'pdf',
        ];
    }

    /**
     * Какие расширения можно прикладывать в этом чате.
     *
     * ⚠️ По типу содержимого судить нельзя: .md и .csv приходят как
     * text/plain, а .xlsx - как application/zip. Поэтому решает расширение,
     * а список лежит в настройке чата (пусто - значения по умолчанию).
     */
    protected function allowedExts()
    {
        $raw = isset($this->setting['file_exts']) ? trim((string)$this->setting['file_exts']) : '';
        if ($raw === '') $raw = 'pdf,xlsx,xlsm,xls,csv,txt,md,png,jpg,jpeg,webp,gif';

        $out = [];
        foreach (explode(',', $raw) as $e) {
            $e = strtolower(trim($e, " \t."));
            if ($e !== '') $out[$e] = true;
        }
        return $out;
    }

    /** Текстовые файлы, которые просто читаем и отдаём модели как текст. */
    protected function textExtensions()
    {
        return ['txt', 'md', 'markdown', 'json', 'xml', 'yml', 'yaml', 'log'];
    }

    /**
     * Текст файла для модели. Режем по объёму: регламент на сотню страниц
     * иначе съест контекст и деньги.
     */
    protected function textToText($file, $name)
    {
        $maxChars = 60000;
        $body = (string)file_get_contents($file);
        if (!mb_check_encoding($body, 'UTF-8')) {
            $body = @iconv('windows-1251', 'UTF-8//IGNORE', $body);
        }
        $body = (string)$body;
        if (mb_strlen($body) > $maxChars) {
            $body = mb_substr($body, 0, $maxChars) . "\n…(файл обрезан)";
        }
        return "Файл «" . $name . "»:\n" . $body;
    }

    /** Расширения таблиц: их читаем сами. */
    protected function sheetExtensions()
    {
        return ['xlsx', 'xlsm', 'xls', 'csv'];
    }

    /**
     * Таблица ли это.
     *
     * ⚠️ Смотрим на расширение, а не только на тип по содержимому: xlsx — это
     * zip, и mime_content_type честно отвечает application/zip, из-за чего файл
     * отбивался как «модель не прочитает».
     */
    protected function isSheet($name, $mime = '')
    {
        $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
        if (in_array($ext, $this->sheetExtensions(), true)) return $ext;
        if ($mime === 'text/csv') return 'csv';
        return '';
    }

    /**
     * Таблица → текст для модели.
     *
     * Отдаём разделённые табуляцией строки: это компактнее любой разметки, а
     * модель такое читает без подсказок. Обрезаем по строкам и по объёму —
     * прайс на три тысячи строк иначе съел бы весь контекст и деньги.
     */
    protected function sheetToText($file, $name)
    {
        $path = gtsAPI::phpExcelPath($this->modx);
        if (!$path) return 'Не удалось прочитать таблицу: на сайте нет библиотеки PHPExcel.';
        require_once $path . 'PHPExcel.php';

        try {
            $reader = PHPExcel_IOFactory::createReaderForFile($file);
            $reader->setReadDataOnly(true);
            $book = $reader->load($file);
        } catch (\Throwable $e) {
            return 'Не удалось прочитать таблицу «' . $name . '»: ' . $e->getMessage();
        }

        $maxRows  = 300;
        $maxChars = 40000;
        $out = [];

        foreach ($book->getWorksheetIterator() as $sheet) {
            // ⚠️ Сначала выбрасываем пустое, и только потом считаем и режем.
            // Excel отдаёт лист «целиком»: в заявке на 17 строк их бывает
            // несколько сотен, и модель получала гору пустоты вместо данных —
            // а в лимит 300 строк настоящие данные могли и не попасть.
            $clean = [];
            foreach ($sheet->toArray(null, true, false, false) as $row) {
                while ($row && ($row[count($row) - 1] === null || trim((string)$row[count($row) - 1]) === '')) {
                    array_pop($row);
                }
                if (!$row) continue;
                $clean[] = $row;
            }

            $total = count($clean);
            if (!$total) continue;

            $out[] = 'Лист «' . $sheet->getTitle() . '», значимых строк: ' . $total
                . ($total > $maxRows ? ' (показаны первые ' . $maxRows . ')' : '');

            foreach (array_slice($clean, 0, $maxRows) as $row) {
                $out[] = implode("\t", array_map(function ($v) {
                    return str_replace(["\t", "\n", "\r"], ' ', (string)$v);
                }, $row));
            }
            $out[] = '';
        }

        $text = implode("\n", $out);
        if (strlen($text) > $maxChars) {
            $text = substr($text, 0, $maxChars) . "\n…обрезано: таблица слишком большая.";
        }
        return $text !== '' ? $text : 'Таблица «' . $name . '» пустая.';
    }

    /**
     * Отдать файл на скачивание.
     *
     * Файлы лежат вне веб-корня, поэтому прямой ссылки на них нет и быть не
     * должно: отдаём через шлюз и только тому, чей это файл (или админу) -
     * иначе номер в ссылке сам становится ключом ко всем чужим выгрузкам.
     */
    protected function actionFile($id)
    {
        $id = (int)$id;
        if (!$id) return $this->error('Не указан файл.');

        $row = $this->modx->getObject('gtsAIFile', $id);
        if (!$row) return $this->error('Файл не найден.');

        $mine  = (int)$row->get('user_id') === (int)$this->modx->user->id;
        $admin = (bool)$this->modx->user->isMember(['Administrator']);
        if (!$mine && !$admin) return $this->error('Это чужой файл.');

        $full = $this->filesPath() . $row->get('path');
        if (!is_file($full)) return $this->error('Файла больше нет на диске.');

        $name = (string)$row->get('name');
        $mime = (string)$row->get('mime');

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . filesize($full));
        // Имя латиницей для старых клиентов + filename* с юникодом: иначе
        // кириллица в имени превращается в вопросительные знаки.
        $ascii = preg_replace('~[^A-Za-z0-9._-]+~', '_', $name);
        header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        readfile($full);
        exit();
    }

    /**
     * Загрузка файла или снимка экрана.
     *
     * Файл кладётся сразу, ещё до отправки сообщения: так виджет показывает
     * вложение и его размер, а не ждёт ответа модели.
     */
    protected function actionUpload(array $request)
    {
        $resp = $this->loadSetting(isset($request['setting']) ? $request['setting'] : '');
        if (!$resp['success']) return $resp;

        if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            return $this->error('Файл не получен.');
        }

        $file  = $_FILES['file'];
        $limit = 8 * 1024 * 1024;
        if ($file['size'] > $limit) {
            return $this->error('Файл больше 8 МБ — столько в запрос к модели не поместится.');
        }

        $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : $file['type'];
        $allowed = $this->allowedMime();
        $sheet   = $this->isSheet($file['name'], $mime);

        // ⚠️ Решает РАСШИРЕНИЕ, а не тип по содержимому: .md и .csv приходят
        // как text/plain, .xlsx - как application/zip. Раньше проверка шла по
        // типу, и markdown отбивался «такой тип файла не прочитать», хотя
        // читать его проще всего. Список расширений - в настройке чата.
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if ($ext === '' && isset($allowed[$mime])) $ext = $allowed[$mime];
        if ($ext === 'jpeg') $ext = 'jpg';

        $exts = $this->allowedExts();
        if ($ext === '' || !isset($exts[$ext])) {
            return $this->error('Файлы «' . ($ext !== '' ? $ext : $mime) . '» в этом чате не принимаются. '
                . 'Разрешены: ' . implode(', ', array_keys($exts)) . '.');
        }
        // Картинку и PDF модель читает сама - для них тип всё же проверяем:
        // подменённое расширение иначе уехало бы ей как изображение.
        $native = ['png', 'jpg', 'webp', 'gif', 'pdf'];
        if (in_array($ext, $native, true) && !isset($allowed[$mime])) {
            return $this->error('Содержимое файла не похоже на ' . $ext . ' (определено как ' . $mime . ').');
        }

        // ⚠️ Разделитель ~, а не /: слеш внутри класса символов закрывал сам
        // шаблон («Unknown modifier»), preg_replace возвращал null, и ЛЮБОЕ
        // имя файла подменялось запасным.
        $raw  = (string)$file['name'];
        $bad  = '~[\\\\/:*?"<>|]+~';
        $name = preg_replace($bad . 'u', '_', $raw);
        // Имя пришло не в UTF-8 — чистим без юникода, чтобы не потерять его целиком.
        if ($name === null) $name = preg_replace($bad, '_', $raw);
        $name = trim(substr((string)$name, 0, 180));
        if ($name === '') $name = 'снимок.' . $ext;

        $stored = date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 10) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $this->filesPath() . $stored)) {
            return $this->error('Не удалось сохранить файл.');
        }

        $obj = $this->modx->newObject('gtsAIFile', [
            'chat_id'   => isset($request['chat_id']) ? (int)$request['chat_id'] : 0,
            'user_id'   => $this->modx->user->id,
            'path'      => $stored,
            'name'      => $name,
            'mime'      => $mime,
            'size'      => (int)$file['size'],
            'createdon' => date('Y-m-d H:i:s'),
        ]);
        if (!$obj->save()) {
            @unlink($this->filesPath() . $stored);
            return $this->error('Не удалось записать файл в историю.');
        }

        return $this->success('', ['data' => [
            'id'   => (int)$obj->id,
            'name' => $name,
            'mime' => $mime,
            'size' => (int)$file['size'],
        ]]);
    }

    /**
     * Содержимое вложений в виде блоков для модели.
     *
     * Изображения и PDF уходят как есть: Claude читает их нативно, OCR и
     * прочие прослойки не нужны.
     *
     * ⚠️ Только для протокола anthropic: у OpenAI картинка передаётся иначе
     * (image_url), и слать ей анропиковские блоки — сломанный запрос.
     */
    protected function fileBlocks(array $ids, $onlyOwn = true)
    {
        if (!$ids) return [];
        if (strtolower($this->conn['protocol']) !== 'anthropic') return [];

        $blocks = [];
        foreach ($ids as $id) {
            $obj = $this->modx->getObject('gtsAIFile', (int)$id);
            if (!$obj) continue;
            // Чужой файл не подцепим, даже если id угадали.
            if ($onlyOwn && (int)$obj->get('user_id') !== (int)$this->modx->user->id
                && !$this->modx->user->isMember(['Administrator'])) continue;

            $full = $this->filesPath() . $obj->get('path');
            if (!is_file($full)) continue;

            $mime = $obj->get('mime');
            $name = $obj->get('name');

            // Таблицу модель не прочитает двоичной — отдаём разобранным текстом.
            if ($this->isSheet($name, $mime)) {
                $blocks[] = [
                    'type' => 'text',
                    'text' => 'Файл «' . $name . '» (таблица):' . "\n" . $this->sheetToText($full, $name),
                ];
                continue;
            }

            $data = base64_encode(file_get_contents($full));

            // Текст и markdown уходят моделью как текст - читать их нечем,
            // кроме как прочитать.
            if (in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $this->textExtensions(), true)) {
                $blocks[] = [
                    'type' => 'text',
                    'text' => $this->textToText($full, $name),
                ];
                continue;
            }

            if ($mime === 'application/pdf') {
                $blocks[] = [
                    'type'   => 'document',
                    'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $data],
                ];
            } else {
                $blocks[] = [
                    'type'   => 'image',
                    'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $data],
                ];
            }
        }
        return $blocks;
    }

    /**
     * Применить отложенную правку — по кнопке пользователя.
     * Что именно применяется, решает СЕРВЕР: берём сохранённую заявку по id,
     * а не то, что прислал клиент. Иначе кнопка стала бы дыркой для любой
     * правки мимо модели.
     */
    protected function actionApply(array $request)
    {
        $resp = $this->loadSetting(isset($request['setting']) ? $request['setting'] : '');
        if (!$resp['success']) return $resp;

        $id = isset($request['pending_id']) ? (int)$request['pending_id'] : 0;
        if (!$id) return $this->error('Не указана правка.');

        if (!$msg = $this->modx->getObject('gtsAIMessage', $id)) {
            return $this->error('Правка не найдена.');
        }
        if ($msg->get('role') !== 'pending') {
            return $this->error('Эта правка уже обработана.');
        }
        if (!$chat = $this->modx->getObject('gtsAIChat', $msg->get('chat_id'))) {
            return $this->error('Чат правки не найден.');
        }
        if (!$this->canSeeChat($chat)) return $this->error('Нет доступа к этой правке.');
        if (empty($this->setting['allow_write'])) return $this->error('Запись запрещена настройкой этого чата.');

        $data = json_decode((string)$msg->get('blocks'), true);
        if (!is_array($data) || empty($data['table']) || empty($data['action']) || empty($data['rows'])) {
            return $this->error('Правка повреждена.');
        }

        $this->chat = $chat;
        $this->needBridge();
        // Область берём из заявки, а запрос — только как запасной путь.
        $scope = !empty($data['scope']) && is_array($data['scope'])
            ? $data['scope']
            : $this->scopeFromRequest($request);
        $applied = $this->applyWrite(
            $data['table'],
            $data['action'],
            (array)$data['rows'],
            $scope,
            isset($data['diff']) ? (array)$data['diff'] : []
        );

        // Заявку закрываем в любом случае: повторное нажатие не должно
        // применить ту же правку ещё раз.
        $msg->set('role', empty($applied['success']) ? 'pending_failed' : 'applied');
        $msg->set('batch_id', isset($applied['batch_id']) ? $applied['batch_id'] : '');
        $msg->save();

        return $applied['success']
            ? $this->success('Применено', ['data' => $applied])
            : $this->error(isset($applied['message']) ? $applied['message'] : 'Не удалось применить', ['data' => $applied]);
    }

    /**
     * Откат пакета правок. Доступен автору правок и администратору.
     *
     * ⚠️ Откат живёт в окне ретеншна лога (gtsapi_log_retention_days, на деве
     * 30 дней): снимков старше этого срока уже нет, и кнопка честно скажет, что
     * откатывать нечего.
     */
    protected function actionRollback(array $request)
    {
        $resp = $this->loadSetting(isset($request['setting']) ? $request['setting'] : '');
        if (!$resp['success']) return $resp;

        $batchId = isset($request['batch_id']) ? (string)$request['batch_id'] : '';
        $batchId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $batchId);
        if ($batchId === '') return $this->error('Не указан пакет правок.');

        $this->needBridge(true);
        $log = $this->bridge->batchLog($batchId);
        if (!$log) {
            return $this->error('Нечего откатывать: записей не найдено. Возможно, срок хранения лога истёк.');
        }

        $isAdmin = (bool)$this->modx->user->isMember(['Administrator']);
        foreach ($log as $row) {
            if (!$isAdmin && (int)$row['user_id'] !== (int)$this->modx->user->id) {
                return $this->error('Откатить может автор правки или администратор.');
            }
        }

        $out = $this->rollbackBatch($batchId);
        return $out['failed']
            ? $this->error("Откатано {$out['restored']}, не удалось {$out['failed']}.", ['data' => $out])
            : $this->success("Откатано строк: {$out['restored']}.", ['data' => $out]);
    }

    /**
     * Потолок на размер ответа инструмента. Результат уезжает в контекст и
     * переотправляется на каждом следующем шаге цикла, поэтому один жадный
     * запрос дорожает кратно числу шагов.
     */
    protected function capResult($result)
    {
        $limit = 40000;
        $json  = json_encode($result, JSON_UNESCAPED_UNICODE);
        if ($json !== false && strlen($json) <= $limit) return $result;

        if (isset($result['rows']) && is_array($result['rows'])) {
            while (count($result['rows']) > 1) {
                $result['rows'] = array_slice($result['rows'], 0, (int)floor(count($result['rows']) / 2));
                $json = json_encode($result, JSON_UNESCAPED_UNICODE);
                if ($json !== false && strlen($json) <= $limit) break;
            }
            $result['truncated'] = 'Ответ урезан: слишком много данных. Сузь фильтры или перечисли нужные поля.';
            return $result;
        }
        return ['error' => 'Ответ слишком большой. Сузь запрос.'];
    }

    /**
     * Область из GET страницы. Это единственный источник: модель такие
     * параметры не передаёт, поэтому в чужой расчёт уйти не может.
     */
    protected function scopeFromRequest(array $request)
    {
        $get = isset($request['get']) && is_array($request['get']) ? $request['get'] : [];
        $out = [];
        foreach (['raschet_id', 'order_id', 'smena_id'] as $key) {
            if (!empty($get[$key])) $out[$key] = (int)$get[$key];
        }
        return $out;
    }

    /** Системный промпт: из настройки + область работы, которую задаёт СЕРВЕР. */
    protected function systemPrompt(array $request, array $scope = [])
    {
        $prompt = isset($this->setting['prompt']) ? (string)$this->setting['prompt'] : '';

        // Доппромпты попадают сюда ТОЛЬКО списком: имя и строчка «когда нужно».
        // Текст модель подгружает инструментом read_prompt, если решит, что
        // правило к делу. Иначе весь свод ехал бы в каждом запросе: это и
        // деньги, и размытое внимание - правило про импорт заявок мешает,
        // когда спрашивают про цену.
        $extras = $this->extraPrompts();
        if ($extras) {
            $prompt .= "\n\nДОПОЛНИТЕЛЬНЫЕ ПРАВИЛА (подгружай инструментом read_prompt по имени,"
                . " когда правило относится к задаче; содержание по названию не угадывай):";
            foreach ($extras as $extra) {
                // Число частей показываем сразу: регламент на сотню страниц
                // приходит кусками, и модель должна понимать это до вызова,
                // а не удивляться обрыву на первой части.
                $parts = count($this->splitForTool((string)$extra['text']));
                $prompt .= "\n- " . $extra['name']
                    . ($extra['description'] !== '' ? ': ' . $extra['description'] : '')
                    . ($parts > 1 ? ' [частей: ' . $parts . ']' : '');
            }
        }

        // Контекст страницы — только адрес и GET. Данные модель берёт
        // инструментами сама: иначе каждое сообщение тащит мегабайт и кэш
        // системного промпта перестаёт работать.
        $page = isset($request['page']) ? (string)$request['page'] : '';
        $get  = isset($request['get']) && is_array($request['get']) ? $request['get'] : [];
        if ($page !== '') {
            $prompt .= "\n\nПользователь находится на странице: " . $page;
        }
        if ($get) {
            $prompt .= "\nПараметры страницы: " . json_encode($get, JSON_UNESCAPED_UNICODE);
        }
        $prompt .= "\n\nПользователь: " . $this->modx->user->get('username');
        return $prompt;
    }

    /**
     * Починка сохранённого шага перед отправкой обратно провайдеру.
     *
     * ⚠️ Инструмент без аргументов уходил как `{}`, но json_decode в массив
     * возвращает его пустым МАССИВОМ, и обратно он кодируется как `[]`.
     * Anthropic на это отвечает 400, и продолжение разговора падало целиком -
     * ровно на тех чатах, где был вызов без аргументов (list_tables).
     */
    protected function healStep(array $step)
    {
        if (!is_array($step['content'])) return $step;

        foreach ($step['content'] as $i => $block) {
            if (($block['type'] ?? '') !== 'tool_use') continue;
            if (!isset($block['input']) || (is_array($block['input']) && !$block['input'])) {
                $step['content'][$i]['input'] = new stdClass();
            }
        }
        return $step;
    }

    /** Таблицы настроек чата: их правка - это правка собственных промптов. */
    protected function promptTables()
    {
        return ['gtsAISetting', 'gtsAIPrompt', 'gtsAISettingFile'];
    }

    /**
     * Можно ли этому чату работать с таблицей.
     *
     * Два списка в настройке чата: белый (пусто - все, у кого стоит флаг ai)
     * и чёрный, который сильнее белого. Поверх - отдельный рубильник на
     * правку собственных промптов: мало того, что таблицы настроек открыты
     * флагом ai, чат должен быть ещё и явно на это уполномочен. Права
     * пользователя при этом никуда не деваются - они потолок в любом случае.
     */
    protected function tableAllowed($table, $write = false)
    {
        $table = (string)$table;

        $list = function ($key) {
            $raw = isset($this->setting[$key]) ? (string)$this->setting[$key] : '';
            return array_filter(array_map('trim', explode(',', $raw)));
        };

        foreach ($list('tables_deny') as $t) {
            if (strcasecmp($t, $table) === 0) return 'Таблица ' . $table . ' закрыта для этого чата.';
        }
        $allow = $list('tables_allow');
        if ($allow) {
            $ok = false;
            foreach ($allow as $t) if (strcasecmp($t, $table) === 0) { $ok = true; break; }
            if (!$ok) {
                return 'Этому чату разрешены только: ' . implode(', ', $allow) . '.';
            }
        }

        if ($write && in_array($table, $this->promptTables(), true)
            && empty($this->setting['allow_prompt_edit'])) {
            return 'Правка собственных промптов в этом чате запрещена настройкой.';
        }
        return '';
    }

    /** Доппромпты настройки по порядку. Выключенные пропускаем. */
    protected function extraPrompts()
    {
        if ($this->extras !== null) return $this->extras;
        if (empty($this->setting['id'])) return $this->extras = [];

        $c = $this->modx->newQuery('gtsAIPrompt');
        $c->where(['setting_id' => (int)$this->setting['id'], 'active' => 1]);
        $c->sortby('rank', 'ASC');
        $c->sortby('id', 'ASC');

        $rows = [];
        foreach ($this->modx->getCollection('gtsAIPrompt', $c) as $row) {
            $rows[] = [
                'name'        => (string)$row->get('name'),
                'description' => trim((string)$row->get('description')),
                'text'        => (string)$row->get('text'),
            ];
        }

        // Правила, которые лежат файлами. Для модели это то же самое правило:
        // имя, «когда нужно» и текст. Разница только в том, где текст хранится -
        // в поле или в файле, который удобнее править снаружи.
        $f = $this->modx->newQuery('gtsAISettingFile');
        $f->where(['setting_id' => (int)$this->setting['id'], 'active' => 1]);
        $f->sortby('rank', 'ASC');
        $f->sortby('id', 'ASC');
        foreach ($this->modx->getCollection('gtsAISettingFile', $f) as $row) {
            $full = $this->settingFilePath((string)$row->get('path'));
            if ($full === '' || !is_file($full)) continue;
            // xlsx/csv разбираем в текст, а не отдаём двоичным: именно так
            // к чату прикладывают шаблон заявки, и модели нужны заголовки
            // листа, а не «файл читается как двоичные данные».
            $fname = (string)$row->get('name');
            $rows[] = [
                'name'        => $fname,
                'description' => trim((string)$row->get('description')),
                'text'        => $this->isSheet($full)
                    ? $this->sheetToText($full, $fname)
                    : $this->textToText($full, $fname),
            ];
        }

        return $this->extras = $rows;
    }

    /**
     * Инструмент чтения правила. Отдаётся модели, только если у настройки
     * вообще есть доппромпты - иначе его описание занимало бы место впустую.
     */
    protected function promptToolDefs()
    {
        if (!$this->extraPrompts()) return [];

        return [[
            'name'         => 'read_prompt',
            'description'  =>
                'Прочитать полный текст дополнительного правила по имени. Имена и краткие описания перечислены в системном промпте.' . "\n" .
                'Зови, когда правило относится к задаче: содержание по названию не угадывай.' . "\n" .
                'Длинное правило приходит частями: в ответе будут part и parts, следующую часть бери тем же инструментом с part=N+1.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Имя правила из списка'],
                    'part' => ['type' => 'integer', 'description' => 'Номер части для длинного правила, по умолчанию 1'],
                ],
                'required'   => ['name'],
            ],
        ]];
    }

    /** Текст правила по имени. */
    protected function readPrompt(array $input)
    {
        $name = isset($input['name']) ? trim((string)$input['name']) : '';
        if ($name === '') return ['error' => 'Не указано имя правила.'];

        $extras = $this->extraPrompts();
        $found = null;
        foreach ($extras as $extra) {
            if ($this->sameName($extra['name'], $name)) { $found = $extra; break; }
        }
        // Запасной заход: имя-начало. Имена правил бывают длинные, и модель
        // обрезает хвост или дописывает лишнее - из-за одного символа гонять
        // её по списку заново незачем.
        if (!$found) {
            $hits = [];
            foreach ($extras as $extra) {
                $a = $this->normName($extra['name']);
                $b = $this->normName($name);
                if ($b !== '' && (strpos($a, $b) === 0 || strpos($b, $a) === 0)) $hits[] = $extra;
            }
            if (count($hits) === 1) $found = $hits[0];
        }

        if (!$found) {
            return ['error' => 'Такого правила нет. Есть: '
                . implode(', ', array_column($extras, 'name')) . '.'];
        }

        // Длинный регламент отдаём частями: целиком он не влезает в потолок
        // ответа инструмента, и раньше модель получала «Ответ слишком большой»
        // без единого способа дочитать.
        $parts = $this->splitForTool((string)$found['text']);
        $total = count($parts);
        $n = isset($input['part']) ? (int)$input['part'] : 1;
        if ($n < 1) $n = 1;
        if ($n > $total) return ['error' => 'У правила «' . $found['name'] . '» всего частей: ' . $total . '.'];

        $out = ['name' => $found['name'], 'text' => $parts[$n - 1]];
        if ($total > 1) {
            $out['part']  = $n;
            $out['parts'] = $total;
            $out['hint']  = $n < $total
                ? 'Это часть ' . $n . ' из ' . $total . '. Дальше: read_prompt с part=' . ($n + 1) . '.'
                : 'Это последняя часть (' . $n . ' из ' . $total . ').';
        }
        return $out;
    }

    /** Имя правила для сравнения: без краёв, без лишних пробелов, в нижнем регистре. */
    protected function normName($v)
    {
        $v = preg_replace('~\s+~u', ' ', (string)$v);
        return mb_strtolower(trim((string)$v), 'UTF-8');
    }

    /**
     * Одно ли это имя.
     *
     * ⚠️ Сравниваем по нормализованному виду: в названии правила легко
     * заводится хвостовой пробел, и тогда точное сравнение не находит его
     * ни с пробелом (модель его не напишет), ни без него.
     */
    protected function sameName($a, $b)
    {
        return $this->normName($a) === $this->normName($b);
    }

    /**
     * Нарезка длинного текста под потолок ответа инструмента (capResult).
     * Режем по строкам, чтобы таблица или список не рвались посередине.
     */
    protected function splitForTool($text, $limit = 28000)
    {
        if (strlen($text) <= $limit) return [$text];

        $parts = [];
        $buf = '';
        foreach (explode("\n", $text) as $line) {
            // Одна строка длиннее куска - режем её саму, иначе кусок не закроется.
            while (strlen($line) > $limit) {
                if ($buf !== '') { $parts[] = $buf; $buf = ''; }
                $parts[] = substr($line, 0, $limit);
                $line = substr($line, $limit);
            }
            if ($buf !== '' && strlen($buf) + strlen($line) + 1 > $limit) {
                $parts[] = $buf;
                $buf = '';
            }
            $buf .= ($buf === '' ? '' : "\n") . $line;
        }
        if ($buf !== '') $parts[] = $buf;
        return $parts;
    }

    /* ------------------------------------------------------------------ *
     *  История
     * ------------------------------------------------------------------ */

    protected function resolveChat(array $request, $firstText)
    {
        if (empty($this->setting['save_history'])) return null;
        // Виджет может попросить не сохранять конкретный разговор.
        if (!empty($request['no_history'])) return null;

        $now = date('Y-m-d H:i:s');

        if (!empty($request['chat_id'])) {
            $chat = $this->modx->getObject('gtsAIChat', (int)$request['chat_id']);
            if ($chat && $this->canSeeChat($chat)) {
                $chat->set('updatedon', $now);
                $chat->save();
                return $chat;
            }
        }

        $title = mb_substr($firstText, 0, 80, 'UTF-8');
        $chat  = $this->modx->newObject('gtsAIChat', [
            'user_id'   => $this->modx->user->id,
            'setting'   => $this->setting['name'],
            'title'     => $title,
            'page_url'  => isset($request['page']) ? mb_substr((string)$request['page'], 0, 255, 'UTF-8') : '',
            'createdon' => $now,
            'updatedon' => $now,
        ]);
        return $chat->save() ? $chat : null;
    }

    /**
     * История чата + новое сообщение в виде, который понимает провайдер.
     *
     * ⚠️ Вложения прошлых сообщений подкладываются заново из файлов на диске.
     * Иначе продолжить разговор о картинке нельзя: в Messages API изображение —
     * это само содержимое, а не ссылка, и модель «забудет» то, что видела.
     */
    protected function buildMessages($chat, $text, array $fileIds = [])
    {
        $messages = [];
        if ($chat) {
            $c = $this->modx->newQuery('gtsAIMessage');
            $c->where(['chat_id' => $chat->id]);
            $c->sortby('id', 'ASC');
            $rows = [];
            foreach ($this->modx->getCollection('gtsAIMessage', $c) as $msg) {
                $rows[] = $msg;
            }

            // Шаги инструментов возвращаем только у ПОСЛЕДНЕГО хода с ними.
            // Иначе каждая прошлая выгрузка ехала бы в каждом новом сообщении,
            // и разговор дорожал бы с каждым ходом; для давних ответов хватает
            // их текста - что сделано, там написано словами.
            //
            // Ищем именно ответ СО ШАГАМИ: ответ без инструментов (а то и
            // пустой, если провайдер сорвался) не должен перекрывать собой
            // предыдущий ход и стирать модели память о сделанном.
            $lastAssistant = -1;
            foreach ($rows as $i => $msg) {
                if ($msg->get('role') !== 'assistant') continue;
                $b = json_decode((string)$msg->get('blocks'), true);
                if (is_array($b) && !empty($b['turn'])) $lastAssistant = $i;
            }

            foreach ($rows as $i => $msg) {
                $role = $msg->get('role');
                if ($role !== 'user' && $role !== 'assistant') continue;
                $content = (string)$msg->get('content');

                $old = [];
                if ($role === 'user') {
                    $blocks = json_decode((string)$msg->get('blocks'), true);
                    if (is_array($blocks) && !empty($blocks['file_ids'])) {
                        $old = $this->fileBlocks((array)$blocks['file_ids']);
                    }
                } elseif ($i === $lastAssistant) {
                    $blocks = json_decode((string)$msg->get('blocks'), true);
                    if (is_array($blocks) && !empty($blocks['turn']) && is_array($blocks['turn'])) {
                        foreach ($blocks['turn'] as $step) {
                            if (!isset($step['role'], $step['content'])) continue;
                            $messages[] = $this->healStep($step);
                        }
                    }
                }
                if ($content === '' && !$old) continue;

                if ($old) {
                    $parts = $old;
                    if ($content !== '') $parts[] = ['type' => 'text', 'text' => $content];
                    $messages[] = ['role' => $role, 'content' => $parts];
                } else {
                    $messages[] = ['role' => $role, 'content' => $content];
                }
            }
        }

        $blocks = $this->fileBlocks($fileIds);
        if ($blocks) {
            // Файлы идут ПЕРЕД текстом: так модель сначала видит документ, а
            // потом вопрос о нём.
            $blocks[] = ['type' => 'text', 'text' => $text];
            $messages[] = ['role' => 'user', 'content' => $blocks];
        } else {
            $messages[] = ['role' => 'user', 'content' => $text];
        }
        return $messages;
    }

    /** Привязать загруженные файлы к чату — для истории и для прав. */
    protected function bindFiles($chat, array $ids)
    {
        if (!$chat || !$ids) return;
        foreach ($ids as $id) {
            $obj = $this->modx->getObject('gtsAIFile', (int)$id);
            if (!$obj || (int)$obj->get('user_id') !== (int)$this->modx->user->id) continue;
            if (!$obj->get('chat_id')) {
                $obj->set('chat_id', $chat->id);
                $obj->save();
            }
        }
    }

    /** Одно сообщение чата. Порядок в истории — порядок записи. */
    protected function saveMessage($chat, $role, $content, $tokensOut = 0, array $fileIds = [], array $turn = [])
    {
        if (!$chat) return;
        // Пустой ответ (провайдер сорвался, текста не было) в историю не пишем:
        // показывать в чате нечего, а модели он только мешает.
        if ($role === 'assistant' && trim((string)$content) === '' && !$turn) return;
        $now = date('Y-m-d H:i:s');

        // В blocks у пользователя - только ССЫЛКИ на файлы: само содержимое
        // лежит на диске и подкладывается при каждой отправке истории.
        // У ответа - шаги хода: что модель спросила у инструментов и что
        // получила. Без них продолжение разговора («продолжай») начиналось
        // с чистого листа: в истории оставался только текст ответа, и модель
        // заново искала то, что уже нашла.
        $blocks = null;
        if ($fileIds) {
            $blocks = json_encode(['file_ids' => $fileIds], JSON_UNESCAPED_UNICODE);
        } elseif ($turn) {
            $packed = json_encode(['turn' => $turn], JSON_UNESCAPED_UNICODE);
            // Ход с десятком выгрузок тянет на сотни килобайт. Храним только
            // то, что не жалко переслать обратно; остальное - как раньше,
            // текстом ответа.
            if ($packed !== false && strlen($packed) <= self::TURN_KEEP_BYTES) $blocks = $packed;
        }

        $this->modx->newObject('gtsAIMessage', [
            'chat_id'    => $chat->id,
            'role'       => $role,
            'content'    => $content,
            'blocks'     => $blocks,
            'tokens_out' => (int)$tokensOut,
            'createdon'  => $now,
        ])->save();

        $chat->set('updatedon', $now);
        $chat->save();
    }

    /* ------------------------------------------------------------------ *
     *  Лог и стоимость
     * ------------------------------------------------------------------ */

    protected function writeLog($chat, array $usage, $started, $status, $error = '', array $tools = [], $iterations = 1)
    {
        $in  = isset($usage['in']) ? (int)$usage['in'] : 0;
        $out = isset($usage['out']) ? (int)$usage['out'] : 0;

        $this->modx->newObject('gtsAILog', [
            'user_id'    => $this->modx->user->id,
            'setting'    => isset($this->setting['name']) ? $this->setting['name'] : '',
            'chat_id'    => $chat ? (int)$chat->id : 0,
            'conn_id'    => isset($this->conn['id']) ? (int)$this->conn['id'] : 0,
            'model'      => isset($this->conn['model']) ? $this->conn['model'] : '',
            'tokens_in'  => $in,
            'tokens_out' => $out,
            'cache_read' => isset($usage['cache_read']) ? (int)$usage['cache_read'] : 0,
            'cost'       => $this->cost($in, $out, isset($usage['cache_read']) ? (int)$usage['cache_read'] : 0),
            'ms'         => (int)round((microtime(true) - $started) * 1000),
            'iterations' => (int)$iterations,
            'tools'      => $tools ? implode(',', array_unique($tools)) : '',
            'status'     => $status,
            'error'      => $error,
            'createdon'  => date('Y-m-d H:i:s'),
        ])->save();
    }

    /**
     * Стоимость вызова в долларах. Цены — в системной настройке gtsai_prices,
     * JSON вида {"claude-opus-5-5":{"in":10,"out":50}} ($ за млн токенов).
     * Нет цены для модели — пишем 0, но токены в логе остаются.
     */
    protected function cost($in, $out, $cacheRead = 0)
    {
        $prices = $this->modx->getOption('gtsai_prices', null, '');
        $prices = $prices ? json_decode($prices, true) : [];
        $model  = isset($this->conn['model']) ? $this->conn['model'] : '';
        if (!is_array($prices) || !isset($prices[$model])) return 0;

        $p = $prices[$model];
        $cin  = isset($p['in'])  ? (float)$p['in']  : 0;
        $cout = isset($p['out']) ? (float)$p['out'] : 0;

        // Чтение из кэша — десятая часть цены входа. Надбавку за ЗАПИСЬ в кэш
        // (она дороже обычного входа) не считаем: провайдер в стриме отдаёт
        // только cache_read, поэтому в логе эта часть расхода не видна.
        return round(
            $in / 1000000 * $cin
            + $cacheRead / 1000000 * $cin * 0.1
            + $out / 1000000 * $cout,
            4
        );
    }
}
