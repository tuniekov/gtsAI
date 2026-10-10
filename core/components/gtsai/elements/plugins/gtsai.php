<?php
/** @var modX $modx */
/* @var array $scriptProperties */

/**
 * Маршрутизация /gtsai в контроллер gtsAI.
 *
 * Тот же приём, что у gtsAPI с /api: ловим OnHandleRequest до того, как MODX
 * начнёт искать ресурс. MODX при этом уже загружен — значит $modx->user,
 * группы и xPDO на месте, и шлюзу не нужна своя авторизация.
 *
 * Стримовые действия (SSE) отдают ответ сами и выходят через exit, сюда не
 * возвращаются. Поэтому JSON-ответ ниже — только для нестримовых действий.
 */
switch ($modx->event->name) {
    case 'OnHandleRequest':
        $uri = explode('?', $_SERVER['REQUEST_URI']);
        $uri = explode('/', $uri[0]);
        if (!isset($uri[1]) || $uri[1] !== 'gtsai') break;

        // Фатальные ошибки (память, max_execution_time, parse) try/catch не ловит,
        // а при стриме браузер увидит просто обрыв. Пишем в лог, чтобы было что
        // искать.
        register_shutdown_function(function () use ($modx, $uri) {
            $e = error_get_last();
            if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR])) {
                $modx->log(modX::LOG_LEVEL_ERROR,
                    '[gtsAI plugin] FATAL: ' . $e['message'] .
                    ' in ' . $e['file'] . ':' . $e['line'] .
                    ' | uri=' . implode('/', $uri) . ' | method=' . (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '')
                );
            }
        });

        // Путь к ядру. Настройку gtsai_core_path уважаем, но нормализуем:
        // установщик подставляет [[+core_path]] пустой строкой, и значение
        // приезжает относительным — сервис по нему не находится, причём молча.
        $corePath = $modx->getOption('gtsai_core_path', $scriptProperties, '');
        $corePath = str_replace('[[+core_path]]', MODX_CORE_PATH, (string)$corePath);
        if ($corePath === '' || !preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $corePath)) {
            $corePath = MODX_CORE_PATH . 'components/gtsai/';
        }

        /** @var gtsAI $gtsAI */
        $gtsAI = $modx->getService('gtsai', 'gtsAI', $corePath . 'model/');

        if (!($gtsAI instanceof gtsAI)) {
            header('Content-Type: application/json; charset=UTF-8');
            exit(json_encode(['success' => false, 'message' => 'Не загружен сервис gtsAI'], JSON_UNESCAPED_UNICODE));
        }

        $start = microtime(true);
        try {
            $resp = $gtsAI->route($uri, $_SERVER['REQUEST_METHOD'], $_REQUEST);
        } catch (\Throwable $e) {
            $modx->log(modX::LOG_LEVEL_ERROR,
                '[gtsAI plugin] EXCEPTION: ' . $e->getMessage() .
                ' in ' . $e->getFile() . ':' . $e->getLine() .
                ' | uri=' . implode('/', $uri) .
                "\nTrace:\n" . $e->getTraceAsString()
            );
            $resp = ['success' => false, 'message' => 'Ошибка сервера: ' . $e->getMessage()];
        }

        header('Content-Type: application/json; charset=UTF-8');
        $resp['time'] = number_format(round(microtime(true) - $start, 7), 7);
        exit(json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
}

return '';
