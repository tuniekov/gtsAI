<?php

/**
 * Протокол Anthropic (Messages API). Основной для нас: gtsai.ru говорит им же
 * и отдаёт стандартные события message_start / content_block_delta / message_stop.
 */
class gtsAIProviderAnthropic extends gtsAIProvider
{
    /** Собираемые на лету аргументы инструментов: index → ['id','name','json'] */
    protected $tools = [];

    public function url()
    {
        return rtrim($this->conn['base_url'], '/') . '/v1/messages';
    }

    public function headers()
    {
        return [
            'content-type: application/json',
            'x-api-key: ' . $this->conn['api_key'],
            'anthropic-version: 2023-06-01',
        ];
    }

    public function body(array $req, $stream = false)
    {
        $body = [
            'model'      => $req['model'],
            'max_tokens' => !empty($req['max_tokens']) ? (int)$req['max_tokens'] : 16000,
            'messages'   => $this->withCacheBreakpoint($req['messages']),
        ];
        if (!empty($req['system'])) {
            // Системный промпт блоком, чтобы можно было повесить cache_control:
            // промпт и описания инструментов стабильны, их выгодно кэшировать.
            $body['system'] = [[
                'type'          => 'text',
                'text'          => $req['system'],
                'cache_control' => ['type' => 'ephemeral'],
            ]];
        }
        if (!empty($req['tools']))  $body['tools']  = $req['tools'];
        if ($stream)                $body['stream'] = true;
        return $body;
    }

    /**
     * Точка кэша на последнем блоке последнего сообщения.
     *
     * Зачем: в цикле инструментов история переотправляется на каждом шаге, и
     * результаты чтения оплачиваются заново. Точка кэша в конце запроса делает
     * весь предыдущий префикс (а это и промпт, и прочитанные строки) чтением из
     * кэша на следующем шаге — впятеро дешевле.
     *
     * Кэш — это префиксное совпадение: любой байт, изменившийся раньше точки,
     * обнуляет её. Поэтому ставим в самый конец и ничего не трогаем выше.
     */
    protected function withCacheBreakpoint(array $messages)
    {
        if (!$messages) return $messages;

        $last = count($messages) - 1;
        $msg  = $messages[$last];

        if (is_string($msg['content'])) {
            $msg['content'] = [[
                'type'          => 'text',
                'text'          => $msg['content'],
                'cache_control' => ['type' => 'ephemeral'],
            ]];
        } elseif (is_array($msg['content']) && $msg['content']) {
            $i = count($msg['content']) - 1;
            $msg['content'][$i]['cache_control'] = ['type' => 'ephemeral'];
        }

        $messages[$last] = $msg;
        return $messages;
    }

    protected function mapEvent($event, array $data)
    {
        $type = isset($data['type']) ? $data['type'] : $event;
        $out  = [];

        switch ($type) {
            case 'message_start':
                if (isset($data['message']['usage'])) {
                    $u = $data['message']['usage'];
                    $out[] = $this->noteUsage(
                        isset($u['input_tokens']) ? $u['input_tokens'] : 0,
                        isset($u['output_tokens']) ? $u['output_tokens'] : 0,
                        isset($u['cache_read_input_tokens']) ? $u['cache_read_input_tokens'] : 0
                    );
                }
                break;

            case 'content_block_start':
                $block = isset($data['content_block']) ? $data['content_block'] : [];
                if (isset($block['type']) && $block['type'] === 'tool_use') {
                    $this->tools[(int)$data['index']] = [
                        'id'   => isset($block['id']) ? $block['id'] : '',
                        'name' => isset($block['name']) ? $block['name'] : '',
                        'json' => '',
                    ];
                }
                break;

            case 'content_block_delta':
                $delta = isset($data['delta']) ? $data['delta'] : [];
                $dtype = isset($delta['type']) ? $delta['type'] : '';
                if ($dtype === 'text_delta' && isset($delta['text'])) {
                    $out[] = ['type' => 'delta', 'text' => $delta['text']];
                } elseif ($dtype === 'input_json_delta') {
                    // Аргументы инструмента приходят кусками JSON — копим до stop.
                    $i = (int)$data['index'];
                    if (isset($this->tools[$i])) {
                        $this->tools[$i]['json'] .= isset($delta['partial_json']) ? $delta['partial_json'] : '';
                    }
                }
                break;

            case 'content_block_stop':
                $i = (int)$data['index'];
                if (isset($this->tools[$i])) {
                    $t = $this->tools[$i];
                    $input = json_decode($t['json'] === '' ? '{}' : $t['json'], true);
                    $out[] = [
                        'type'  => 'tool',
                        'id'    => $t['id'],
                        'name'  => $t['name'],
                        // Аргументы всегда через json_decode: экранирование строк
                        // у моделей разное, сравнивать сырой JSON нельзя.
                        'input' => is_array($input) ? $input : [],
                    ];
                    unset($this->tools[$i]);
                }
                break;

            case 'message_delta':
                if (isset($data['usage'])) {
                    $u = $data['usage'];
                    $out[] = $this->noteUsage(
                        isset($u['input_tokens']) ? $u['input_tokens'] : 0,
                        isset($u['output_tokens']) ? $u['output_tokens'] : 0,
                        isset($u['cache_read_input_tokens']) ? $u['cache_read_input_tokens'] : 0
                    );
                }
                if (isset($data['delta']['stop_reason'])) {
                    $out[] = ['type' => 'stop_reason', 'stop_reason' => $data['delta']['stop_reason']];
                }
                break;

            case 'message_stop':
                $out[] = ['type' => 'done', 'stop_reason' => ''];
                break;
        }

        return $out;
    }

    protected function mapResponse(array $json)
    {
        $out = [];
        if (isset($json['usage'])) {
            $u = $json['usage'];
            $out[] = $this->noteUsage(
                isset($u['input_tokens']) ? $u['input_tokens'] : 0,
                isset($u['output_tokens']) ? $u['output_tokens'] : 0,
                isset($u['cache_read_input_tokens']) ? $u['cache_read_input_tokens'] : 0
            );
        }
        foreach (isset($json['content']) ? $json['content'] : [] as $block) {
            if (!isset($block['type'])) continue;
            if ($block['type'] === 'text') {
                $out[] = ['type' => 'delta', 'text' => $block['text']];
            } elseif ($block['type'] === 'tool_use') {
                $out[] = [
                    'type'  => 'tool',
                    'id'    => isset($block['id']) ? $block['id'] : '',
                    'name'  => isset($block['name']) ? $block['name'] : '',
                    'input' => isset($block['input']) ? $block['input'] : [],
                ];
            }
        }
        $out[] = [
            'type'        => 'done',
            'stop_reason' => isset($json['stop_reason']) ? $json['stop_reason'] : '',
        ];
        return $out;
    }
}
