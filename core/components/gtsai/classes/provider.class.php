<?php

/**
 * Провайдер ИИ: один протокол — один класс-наследник.
 *
 * Системы плагинов провайдеров сознательно нет: протокол выбирается полем
 * gtsAIConn.protocol (anthropic|openai), новый протокол — новый наследник.
 *
 * Наружу оба протокола отдают ОДИНАКОВЫЕ события, чтобы виджет ничего не знал
 * о провайдере:
 *   ['type'=>'delta',  'text'=>'...']            — кусок текста ответа
 *   ['type'=>'usage',  'in'=>N, 'out'=>N, 'cache_read'=>N]
 *   ['type'=>'tool',   'id'=>'...', 'name'=>'...', 'input'=>[...]]
 *   ['type'=>'done',   'stop_reason'=>'...']
 *   ['type'=>'error',  'message'=>'...']
 */
abstract class gtsAIProvider
{
    /** @var array подключение: base_url, api_key, model */
    protected $conn = [];

    /** @var string накопитель незакрытых SSE-кадров (кадр может прийти разрезанным) */
    protected $buf = '';

    /** @var array агрегат по токенам за вызов */
    protected $usage = ['in' => 0, 'out' => 0, 'cache_read' => 0];

    public function __construct(array $conn)
    {
        $this->conn = $conn;
    }

    /** Фабрика по полю protocol. */
    public static function factory(array $conn)
    {
        $protocol = !empty($conn['protocol']) ? strtolower($conn['protocol']) : 'anthropic';
        $dir = __DIR__ . '/providers/';
        $map = [
            'anthropic' => ['gtsAIProviderAnthropic', $dir . 'anthropic.class.php'],
            'openai'    => ['gtsAIProviderOpenAI',    $dir . 'openai.class.php'],
        ];
        if (!isset($map[$protocol])) return null;
        list($class, $file) = $map[$protocol];
        if (!class_exists($class)) {
            if (!file_exists($file)) return null;
            require_once $file;
        }
        return new $class($conn);
    }

    public function getUsage()
    {
        return $this->usage;
    }

    /** Полный адрес эндпоинта. */
    abstract public function url();

    /** Адрес списка моделей. */
    public function modelsUrl()
    {
        return rtrim($this->conn['base_url'], '/') . '/v1/models';
    }

    /**
     * Какие модели даёт подключение.
     *
     * Список спрашиваем у самого провайдера, а не держим свой: он меняется
     * чаще, чем мы выкладываем правки, и зашитый перечень устарел бы молча.
     */
    public function models()
    {
        $ch = curl_init($this->modelsUrl());
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $this->headers(),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $out  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err)       return ['success' => false, 'message' => 'Сеть: ' . $err];
        $json = json_decode($out, true);
        if (!is_array($json)) return ['success' => false, 'message' => 'Провайдер вернул не JSON (' . $code . ')'];
        if ($code >= 400) {
            $msg = isset($json['error']['message']) ? $json['error']['message'] : ('ошибка ' . $code);
            return ['success' => false, 'message' => $msg];
        }

        // Оба протокола отдают список в data: [{id, …}].
        $rows = [];
        foreach ((array)(isset($json['data']) ? $json['data'] : []) as $m) {
            if (empty($m['id'])) continue;
            $rows[] = [
                'id'   => (string)$m['id'],
                'name' => isset($m['display_name']) ? (string)$m['display_name'] : (string)$m['id'],
            ];
        }
        return ['success' => true, 'models' => $rows];
    }

    /** HTTP-заголовки запроса. */
    abstract public function headers();

    /**
     * Тело запроса из нормализованного вида.
     * $req: ['model','system','messages','tools','max_tokens']
     */
    abstract public function body(array $req, $stream = false);

    /** Один разобранный SSE-кадр → ноль или несколько наших событий. */
    abstract protected function mapEvent($event, array $data);

    /** Ответ нестримового запроса → наши события. */
    abstract protected function mapResponse(array $json);

    /**
     * Стрим. Колбэк зовётся на каждое НАШЕ событие, по мере прихода данных.
     *
     * curl стримит сам: CURLOPT_WRITEFUNCTION вызывается на каждый чанк. Два
     * обязательных условия — НЕ ставить CURLOPT_RETURNTRANSFER (иначе всё
     * соберётся в строку и колбэк не поможет) и CURLOPT_TIMEOUT = 0
     * (ограничиваем только установку соединения).
     */
    public function stream(array $req, callable $onEvent)
    {
        // Повтор только тогда, когда не пришло НИ ОДНОГО байта: соединение не
        // встало, модель ничего не сделала, повторять нечего бояться. Если
        // поток уже начался, второй попытки быть не может — часть ответа
        // (и, возможно, вызовы инструментов) уже ушла наружу.
        $attempt = 0;
        while (true) {
            $got = false;
            $res = $this->streamOnce($req, function ($ev) use ($onEvent, &$got) {
                $got = true;
                $onEvent($ev);
            }, $netError);

            if ($res !== false || $got || $netError === '' || $attempt >= self::CONNECT_RETRIES) {
                if ($res === false && !$got) {
                    $onEvent(['type' => 'error', 'message' => $netError !== ''
                        ? 'Сеть: ' . $netError
                        : 'Провайдер не ответил.']);
                }
                return $res;
            }

            $attempt++;
            // Пауза растёт: 1 с, 3 с. Провайдер обычно отлипает за эти секунды.
            sleep($attempt * 2 - 1);
        }
    }

    /** Сколько раз повторить вызов, если соединение так и не встало. */
    const CONNECT_RETRIES = 2;

    /**
     * Одна попытка стрима. Сетевую ошибку отдаёт через $netError, а не
     * событием: решение, повторять или показывать, принимает stream().
     */
    protected function streamOnce(array $req, callable $onEvent, &$netError)
    {
        $netError = '';
        $this->buf = '';
        $ch = curl_init($this->url());
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $this->headers(),
            CURLOPT_POSTFIELDS     => json_encode($this->body($req, true), JSON_UNESCAPED_UNICODE),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_TCP_NODELAY    => true,
            CURLOPT_BUFFERSIZE     => 1024,
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use ($onEvent) {
                $this->buf .= $chunk;
                // Кадры SSE разделены пустой строкой. Разрезанный между чанками
                // кадр остаётся в буфере до следующего прихода.
                while (($pos = strpos($this->buf, "\n\n")) !== false) {
                    $frame = substr($this->buf, 0, $pos);
                    $this->buf = substr($this->buf, $pos + 2);
                    foreach ($this->parseFrame($frame) as $ev) {
                        $onEvent($ev);
                    }
                }
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            $netError = $err;
            return false;
        }
        if ($code >= 400) {
            $onEvent(['type' => 'error', 'message' => 'Провайдер ответил ' . $code]);
            return false;
        }
        return true;
    }

    /** Нестримовый вызов — для разбора файлов и других задач без чата. */
    public function send(array $req)
    {
        $ch = curl_init($this->url());
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $this->headers(),
            CURLOPT_POSTFIELDS     => json_encode($this->body($req, false), JSON_UNESCAPED_UNICODE),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 600,
        ]);
        $out  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) return [['type' => 'error', 'message' => 'Сеть: ' . $err]];
        $json = json_decode($out, true);
        if (!is_array($json)) {
            return [['type' => 'error', 'message' => 'Провайдер вернул не JSON (' . $code . ')']];
        }
        if ($code >= 400) {
            $msg = isset($json['error']['message']) ? $json['error']['message'] : ('ошибка ' . $code);
            return [['type' => 'error', 'message' => $msg]];
        }
        return $this->mapResponse($json);
    }

    /** Разбор кадра SSE: строки event: и data:. */
    protected function parseFrame($frame)
    {
        $event = '';
        $data  = '';
        foreach (preg_split('/\r?\n/', $frame) as $line) {
            if (strpos($line, 'event:') === 0) {
                $event = trim(substr($line, 6));
            } elseif (strpos($line, 'data:') === 0) {
                $data .= ltrim(substr($line, 5));
            }
        }
        if ($data === '') return [];
        if (trim($data) === '[DONE]') return [['type' => 'done', 'stop_reason' => 'end_turn']];

        $json = json_decode($data, true);
        if (!is_array($json)) return [];
        if (isset($json['error'])) {
            $msg = isset($json['error']['message']) ? $json['error']['message'] : 'ошибка провайдера';
            return [['type' => 'error', 'message' => $msg]];
        }
        return $this->mapEvent($event, $json);
    }

    /** Запомнить расход токенов и отдать событие usage. */
    protected function noteUsage($in, $out, $cacheRead = 0)
    {
        if ($in)        $this->usage['in']         = (int)$in;
        if ($out)       $this->usage['out']        = (int)$out;
        if ($cacheRead) $this->usage['cache_read'] = (int)$cacheRead;
        return ['type' => 'usage'] + $this->usage;
    }
}
