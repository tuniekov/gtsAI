export default {
    // Цены моделей для подсчёта стоимости в логе: $ за млн токенов.
    // Нет модели в списке — в лог пишется 0, но токены остаются.
    'prices': {
        xtype: 'textarea',
        value: '{"claude-opus-5-5":{"in":10,"out":50},"claude-opus-5":{"in":5,"out":25},"claude-sonnet-5":{"in":2,"out":10}}',
        area: 'gtsai_main',
    },
    // Настройки `gtsai_p_chat` здесь нет намеренно: её держит резолвер страниц
    // (`_build/configs/resources.js` → `config_name`) и хранит в ней id страницы
    // «Чат ИИ». Что на странице показывать — задаётся не настройкой, а файлом
    // `core/components/gtsai/elements/resources/web/ai.tpl`.

    // Источник файлов для вложений настройки чата. Здесь имя источника
    // (или его номер): у одного и того же источника на разных площадках
    // разные id, поэтому в конфиге таблицы стоит имя ЭТОЙ настройки, а не
    // число. Пусто - поле выбора файла останется без источника.
    'media_source': {
        xtype: 'textfield',
        value: 'gtsAPIFile',
        area: 'gtsai_main',
    },

    // Где лежат вложения чата: присланные файлы и собранные моделью выгрузки.
    // Пусто - core/components/gtsai/files/ (каталог компонента). Можно указать
    // свой путь: абсолютный или от корня сайта.
    //
    // ⚠️ Каталог должен быть ВНЕ веб-корня: файлы выдаёт шлюз по правам
    // (/gtsai/file/{id}), прямой ссылки на них быть не должно.
    'files_path': {
        xtype: 'textfield',
        value: '',
        area: 'gtsai_main',
    },

    // --- Доступ к базе только на чтение ---------------------------------
    //
    // ⚠️ Это ОТДЕЛЬНЫЙ пользователь MySQL с правом SELECT, а НЕ коннект MODX.
    // Как его завести — в README.md пакета. Пусто — инструмент SQL не работает,
    // даже если он разрешён в настройке чата.
    'sql_dsn': {
        xtype: 'textfield',
        value: '',
        area: 'gtsai_sql',
    },
    'sql_user': {
        xtype: 'textfield',
        value: '',
        area: 'gtsai_sql',
    },
    'sql_password': {
        xtype: 'text-password',
        value: '',
        area: 'gtsai_sql',
    },
    // Таблицы, которые не показываем никогда, даже на чтение.
    // Таблицы, закрытые даже на чтение. Имена пишутся БЕЗ префикса:
    // префикс у каждой площадки свой, а с зашитым «modx_» запрет на чужом
    // префиксе молча переставал работать.
    'sql_deny': {
        xtype: 'textfield',
        value: 'users,user_attributes,gtsapi_tokens',
        area: 'gtsai_sql',
    },

    // Страница админки: что показать в пункте меню gtsAI.
    // JSON здесь, а не в params пункта меню: меню менеджера рендерится через
    // Smarty, и фигурные скобки в params ломают страницу менеджера.
    'admin': {
        xtype: 'textarea',
        value: '{"mixVue":{"app":"gtsai","config":{"setting":"default","echo":true,"dock":"inline"}},"PVTabs":{"tabs":{"gtsAIConn":{"title":"Подключения","table":"gtsAIConn"},"gtsAISetting":{"title":"Настройки чатов","table":"gtsAISetting"},"gtsAIChat":{"title":"Чаты","table":"gtsAIChat"},"gtsAILog":{"title":"Лог вызовов","table":"gtsAILog"}}}}',
        area: 'gtsai_main',
    },
}
