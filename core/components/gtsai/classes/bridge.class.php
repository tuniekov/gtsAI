<?php

/**
 * Мост gtsAI → gtsAPI.
 *
 * Принцип: ИИ ходит за данными ТЕМ ЖЕ путём, что менеджер мышкой, — через
 * обычный CRUD gtsAPI. Отсюда бесплатно получаем права, триггеры, пересчёт и
 * лог. Своего доступа к базе у ИИ нет.
 *
 * Сужение по сравнению с человеком — в gtsAPI (table.class.php::checkAIAccess),
 * включается флагом config.ai_mode, который ставит этот мост:
 *   - таблица должна быть помечена `ai = 1`;
 *   - действие должно быть помечено в конфиге таблицы: actions: { update:
 *     {'ai': 1} }. Нет пометки — нет действия, даже если у юзера права есть;
 *   - у чата должен стоять общий рубильник записи (allow_write);
 *   - вызов «пакет/метод» вдобавок обязан быть объявлен в actions таблицы.
 */
class gtsAIBridge
{
    /** @var modX */
    protected $modx;

    /** @var gtsAPI свежий экземпляр, чтобы флаг ai_mode не протёк в обычные запросы */
    protected $api;

    /**
     * @var array рубильники уровня чата: write (запись вообще) и rollback.
     * ЧТО именно можно — решает конфиг таблицы: actions: { update: {'ai': 1} }.
     */
    protected $mode = [];

    public function __construct(modX &$modx, array $mode = [])
    {
        $this->modx = &$modx;
        $this->mode = array_merge(['write' => false, 'rollback' => false], $mode);

        $corePath = MODX_CORE_PATH . 'components/gtsapi/';
        require_once $corePath . 'model/gtsapi.class.php';
        $this->api = new gtsAPI($this->modx, ['ai_mode' => $this->mode]);
    }

    /**
     * Разрешить откат на уже созданном мосте. Отдельно от записи: откат
     * инициирует пользователь кнопкой, модель этого действия не получает.
     */
    public function allowRollback()
    {
        $this->mode['rollback'] = true;
        $this->api->config['ai_mode'] = $this->mode;
    }

    /**
     * Таблицы, которые видит ИИ И видит текущий пользователь.
     * Проверка прав повторяет gtsAPI::checkPermissions на уровне таблицы —
     * сам запрос её всё равно сделает ещё раз, здесь это нужно только чтобы не
     * показывать модели то, чего она не откроет.
     */
    public function listTables()
    {
        $c = $this->modx->newQuery('gtsAPITable');
        $c->where(['ai' => 1, 'active' => 1]);
        // ⚠️ Без sortby('table'): `table` — зарезервированное слово MySQL, а xPDO
        // в sortby его не экранирует. Получается «ORDER BY table ASC», запрос
        // падает МОЛЧА и коллекция приходит пустой. Сортируем в PHP.

        $rows = [];
        foreach ($this->modx->getCollection('gtsAPITable', $c) as $obj) {
            $rule = $obj->toArray();
            if (!$this->userCanTable($rule)) continue;
            $rows[] = [
                'table'       => $rule['table'],
                'description' => (string)$rule['description'],
            ];
        }
        usort($rows, function ($a, $b) {
            return strcmp($a['table'], $b['table']);
        });
        return $rows;
    }

    protected function userCanTable(array $rule)
    {
        if (!empty($rule['authenticated']) && !($this->modx->user->id > 0)) return false;
        if (!empty($rule['groups'])) {
            $groups = array_map('trim', explode(',', $rule['groups']));
            if (!$this->modx->user->isMember($groups)) return false;
        }
        if (!empty($rule['permissions'])) {
            foreach (array_map('trim', explode(',', $rule['permissions'])) as $pm) {
                if (!$this->modx->hasPermission($pm)) return false;
            }
        }
        return true;
    }

    /**
     * Конфиг таблицы: поля, фильтры, действия, лимит.
     *
     * Берётся действием options — тем же, которым конфиг получает интерфейс.
     * Руками ничего не описываем: что умеет таблица, уже описано в gtsAPI, и
     * ИИ видит ровно то же, что видит менеджер.
     */
    public function tableConfig($table)
    {
        $resp = $this->call($table, 'options', ['limit' => 1]);
        if (!$resp['success']) return $resp;

        $data = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : [];

        $fields = [];
        foreach ((array)(isset($data['fields']) ? $data['fields'] : []) as $name => $f) {
            $one = [];
            foreach (['label', 'type', 'table', 'readonly', 'field', 'FractionDigits'] as $k) {
                if (isset($f[$k]) && $f[$k] !== '') $one[$k] = $f[$k];
            }
            // select-поля: без списка значений модель не знает, что можно ставить.
            if (isset($f['select_data'])) $one['select_data'] = $f['select_data'];
            $fields[$name] = $one;
        }

        // Какие действия ИИ реально доступны — видно по пометке ai в конфиге.
        $actions = [];
        foreach ((array)(isset($data['actions']) ? $data['actions'] : []) as $name => $cfg) {
            if (!is_array($cfg)) continue;
            $actions[$name] = !empty($cfg['ai']);
        }

        return [
            'success'  => true,
            'table'    => $table,
            'fields'   => $fields,
            'filters'  => isset($data['filters']) ? $data['filters'] : null,
            'actions'  => $actions,
            'limit'    => isset($data['limit']) ? $data['limit'] : null,
        ];
    }

    /**
     * Чтение строк — СКВОЗНОЙ проброс запроса в gtsAPI.
     *
     * Принимаем тот же запрос, что шлёт интерфейс: filters (с matchMode и
     * constraints), sortField/sortOrder, multiSortMeta, limit, offset, ids.
     * Свой узкий набор параметров тут не нужен — у таблицы уже есть контракт,
     * и переписывать его половинками значит каждый раз чего-то недодать
     * (так потерялись сначала total, потом сортировка).
     *
     * Сервер добавляет от себя только три вещи: область работы, потолок
     * лимита и подсчёт общего количества.
     */
    public function read($table, array $request, array $scope = [], array $fields = [], $action = 'read')
    {
        // autocomplete — тот же поиск по названию, которым пользуется
        // интерфейс: одна строка запроса вместо перебора каталога страницами.
        $action = $action === 'autocomplete' ? 'autocomplete' : 'read';
        $limit = isset($request['limit']) ? (int)$request['limit'] : 20;
        $request['limit'] = max(1, min($limit, 50));
        $request['offset'] = isset($request['offset']) ? max(0, (int)$request['offset']) : 0;
        // Общее количество нужно всегда: без него модель не знает, поместилась
        // выборка или надо листать, и начинает перебирать таблицу вслепую.
        $request['setTotal'] = 1;

        $filters = isset($request['filters']) && is_array($request['filters']) ? $request['filters'] : [];
        $request['filters'] = array_merge($filters, $this->scopeFor($table, $scope));

        $resp = $this->call($table, $action, $request);
        if (!$resp['success']) return $resp;

        $data = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : [];
        // У автокомплита ответ приходит списком подсказок, а не rows.
        $rows = isset($data['rows']) ? $data['rows'] : (isset($data['results']) ? $data['results'] : (is_array($data) && isset($data[0]) ? $data : []));

        // Просили конкретные колонки — остальные не тащим в контекст.
        if ($fields && $rows) {
            $keep = array_fill_keys($fields, true);
            $keep['id'] = true;
            foreach ($rows as $i => $row) {
                $rows[$i] = array_intersect_key((array)$row, $keep);
            }
        }

        return [
            'success' => true,
            'total'   => isset($data['total']) ? (int)$data['total'] : null,
            'offset'  => $request['offset'],
            'rows'    => $rows,
        ];
    }

    /**
     * Пишущее действие над строкой — СКВОЗНОЙ проброс в gtsAPI.
     *
     * Никаких своих «функций записи»: действие называется так же, как в конфиге
     * таблицы (update, create, insert, пакет/метод), а payload повторяет то,
     * что шлёт интерфейс. Контракт уже описан в gtsAPI, и половинить его
     * нельзя — иначе каждый раз чего-то недостаёт.
     *
     * $batchId уходит в gtsAPILog и склеивает N правок одного жеста: по нему
     * потом откатывается всё разом, а не построчно.
     */
    public function writeRow($table, $action, array $row, $batchId, array $scope = [])
    {
        $request = $row;

        // ⚠️ Область работы уходит ФИЛЬТРАМИ, а не обычным полем.
        // Так её передаёт интерфейс, и из фильтров gtsAPI берёт значения по
        // умолчанию при создании (addDefaultFields). Полем она отсеивалась
        // защитой от массового присвоения: строка создавалась с raschet_id = 0,
        // и пересчёт падал с «Не найден расчет 0».
        $scopeFields = $this->scopeFor($table, $scope);
        if ($scopeFields) {
            $filters = isset($request['filters']) && is_array($request['filters']) ? $request['filters'] : [];
            $request['filters'] = array_merge($filters, $scopeFields);
        }

        $request['batch_id'] = $batchId;
        return $this->call($table, $action, $request);
    }

    /**
     * Текущие значения строки — нужны, чтобы показать «было → стало».
     *
     * ⚠️ Область работы обязательна. Строки расчёта без raschet_id не читаются
     * вовсе (так устроена защита gtsShop), и снимок приходил пустым — правка
     * отменялась с сообщением «строка недоступна», хотя строка на месте.
     */
    public function rowSnapshot($table, $id, array $fields = [], array $scope = [])
    {
        $resp = $this->read($table, ['filters' => ['id' => (int)$id], 'limit' => 1], $scope, $fields);
        if (!$resp['success']) return null;
        return isset($resp['rows'][0]) ? (array)$resp['rows'][0] : null;
    }

    /**
     * Записи лога одного пакета правок, новые первыми.
     * Порядок важен: откатывать надо в обратную сторону, иначе две правки
     * одной строки развернутся не туда.
     */
    public function batchLog($batchId)
    {
        $c = $this->modx->newQuery('gtsAPILog');
        $c->where(['batch_id' => $batchId]);
        $c->sortby('id', 'DESC');

        $rows = [];
        foreach ($this->modx->getCollection('gtsAPILog', $c) as $obj) {
            $rows[] = $obj->toArray();
        }
        return $rows;
    }

    /** Откат одной записи лога к состоянию «до правки». */
    public function restoreVersion($table, $versionId)
    {
        return $this->call($table, 'restore_version', ['version_id' => (int)$versionId]);
    }

    /**
     * Удалить строку, которую ИИ только что создал.
     *
     * Это действие ШЛЮЗА, а не модели: созданную строку нечем вернуть через
     * restore_version (он правит существующую), поэтому откат создания — это
     * удаление ровно того id, который записан в логе этого же пакета.
     */
    public function deleteRow($table, $id)
    {
        return $this->call($table, 'delete', ['id' => (int)$id, 'ids' => (string)(int)$id]);
    }

    /**
     * Область работы, которую задаёт сервер (например raschet_id открытой
     * страницы). Применяется только к таблицам, у которых такое поле есть, —
     * иначе фильтр по чужому полю уронил бы запрос. Модель эти значения не
     * передаёт и перебить не может: они приходят из GET страницы.
     */
    protected function scopeFor($table, array $scope)
    {
        if (!$scope) return [];

        $obj = $this->modx->getObject('gtsAPITable', ['table' => $table, 'active' => 1]);
        if (!$obj) return [];
        $class = $obj->get('class') ? $obj->get('class') : $obj->get('table');

        $fields = $this->modx->getFields($class);
        if (!is_array($fields)) return [];

        $out = [];
        foreach ($scope as $k => $v) {
            if (array_key_exists($k, $fields)) $out[$k] = $v;
        }
        return $out;
    }

    /** Вызов gtsAPI так, будто пришёл обычный запрос на /api/{table}. */
    public function call($table, $action, array $request = [])
    {
        $request['api_action'] = $action;
        $uri = ['', 'api', $table];

        try {
            $resp = $this->api->route($uri, 'POST', $request);
        } catch (\Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[gtsAI bridge] ' . $e->getMessage());
            return ['success' => false, 'message' => 'Ошибка обращения к данным: ' . $e->getMessage()];
        }
        if (!is_array($resp)) return ['success' => false, 'message' => 'Пустой ответ gtsAPI'];
        return $resp;
    }
}
