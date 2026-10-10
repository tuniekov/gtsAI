<?php

/**
 * OpenAI-совместимый протокол (chat/completions). Нужен, чтобы можно было
 * подключить любой совместимый шлюз, не переписывая виджет.
 *
 * Отличия от Anthropic, из которых растёт весь код ниже:
 *   - системный промпт не отдельное поле, а первое сообщение role: system;
 *   - дельты лежат в choices[0].delta.content;
 *   - конец стрима — литерал [DONE], а не событие;
 *   - аргументы инструментов приходят кусками в tool_calls[].function.arguments.
 */
class gtsAIProviderOpenAI extends gtsAIProvider
{
    /** Собираемые на лету вызовы инструментов: index → ['id','name','json'] */
    protected $tools = [];

    public function url()
    {
        return rtrim($this->conn['base_url'], '/') . '/v1/chat/completions';
    }

    public function headers()
    {
        return [
            'content-type: application/json',
            'authorization: Bearer ' . $this->conn['api_key'],
        ];
    }

    public function body(array $req, $stream = false)
    {
        $messages = $req['messages'];
        if (!empty($req['system'])) {
            array_unshift($messages, ['role' => 'system', 'content' => $req['system']]);
        }
        $body = [
            'model'      => $req['model'],
            'max_tokens' => !empty($req['max_tokens']) ? (int)$req['max_tokens'] : 16000,
            'messages'   => $messages,
        ];
        if (!empty($req['tools'])) {
            // Описания инструментов у нас в анропиковском виде — переводим.
            $body['tools'] = array_map(function ($t) {
                return [
                    'type'     => 'function',
                    'function' => [
                        'name'        => $t['name'],
                        'description' => isset($t['description']) ? $t['description'] : '',
                        'parameters'  => isset($t['input_schema']) ? $t['input_schema'] : new stdClass(),
                    ],
                ];
            }, $req['tools']);
        }
        if ($stream) {
            $body['stream'] = true;
            // Иначе расход токенов в стриме не приходит вообще.
            $body['stream_options'] = ['include_usage' => true];
        }
        return $body;
    }

    protected function mapEvent($event, array $data)
    {
        $out = [];

        if (isset($data['usage']) && is_array($data['usage'])) {
            $u = $data['usage'];
            $out[] = $this->noteUsage(
                isset($u['prompt_tokens']) ? $u['prompt_tokens'] : 0,
                isset($u['completion_tokens']) ? $u['completion_tokens'] : 0
            );
        }

        $choice = isset($data['choices'][0]) ? $data['choices'][0] : [];
        $delta  = isset($choice['delta']) ? $choice['delta'] : [];

        if (!empty($delta['content'])) {
            $out[] = ['type' => 'delta', 'text' => $delta['content']];
        }

        foreach (isset($delta['tool_calls']) ? $delta['tool_calls'] : [] as $call) {
            $i = isset($call['index']) ? (int)$call['index'] : 0;
            if (!isset($this->tools[$i])) {
                $this->tools[$i] = ['id' => '', 'name' => '', 'json' => ''];
            }
            if (!empty($call['id']))                     $this->tools[$i]['id']    = $call['id'];
            if (!empty($call['function']['name']))       $this->tools[$i]['name']  = $call['function']['name'];
            if (isset($call['function']['arguments']))   $this->tools[$i]['json'] .= $call['function']['arguments'];
        }

        $finish = isset($choice['finish_reason']) ? $choice['finish_reason'] : '';
        if ($finish) {
            foreach ($this->tools as $t) {
                $input = json_decode($t['json'] === '' ? '{}' : $t['json'], true);
                $out[] = [
                    'type'  => 'tool',
                    'id'    => $t['id'],
                    'name'  => $t['name'],
                    'input' => is_array($input) ? $input : [],
                ];
            }
            $this->tools = [];
            $out[] = ['type' => 'stop_reason', 'stop_reason' => $finish];
        }

        return $out;
    }

    protected function mapResponse(array $json)
    {
        $out = [];
        if (isset($json['usage'])) {
            $u = $json['usage'];
            $out[] = $this->noteUsage(
                isset($u['prompt_tokens']) ? $u['prompt_tokens'] : 0,
                isset($u['completion_tokens']) ? $u['completion_tokens'] : 0
            );
        }
        $msg = isset($json['choices'][0]['message']) ? $json['choices'][0]['message'] : [];
        if (!empty($msg['content'])) {
            $out[] = ['type' => 'delta', 'text' => $msg['content']];
        }
        foreach (isset($msg['tool_calls']) ? $msg['tool_calls'] : [] as $call) {
            $input = json_decode(isset($call['function']['arguments']) ? $call['function']['arguments'] : '{}', true);
            $out[] = [
                'type'  => 'tool',
                'id'    => isset($call['id']) ? $call['id'] : '',
                'name'  => isset($call['function']['name']) ? $call['function']['name'] : '',
                'input' => is_array($input) ? $input : [],
            ];
        }
        $out[] = [
            'type'        => 'done',
            'stop_reason' => isset($json['choices'][0]['finish_reason']) ? $json['choices'][0]['finish_reason'] : '',
        ];
        return $out;
    }
}
