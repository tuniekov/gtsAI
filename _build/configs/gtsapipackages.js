export default {
    gtsai: {
        name: 'gtsai',
        gtsAPITables: {
            // Подключения к ИИ. Только администратор: здесь лежит ключ.
            gtsAIConn: {
                table: 'gtsAIConn',
                class: 'gtsAIConn',
                version: 1,
                authenticated: true,
                groups: 'Administrator',
                active: true,
                properties: {
                    actions: { read: {}, create: {}, update: {}, delete: {} },
                    autocomplete: { tpl: '{$name}', limit: 0 },
                    fields: {
                        id: { type: 'view', class: 'gtsAIConn' },
                        name: { label: 'Название', type: 'text', class: 'gtsAIConn' },
                        protocol: {
                            label: 'Протокол',
                            type: 'select',
                            select_data: [
                                { id: 'anthropic', content: 'Anthropic (Messages API)' },
                                { id: 'openai', content: 'OpenAI-совместимый' },
                            ],
                            class: 'gtsAIConn',
                        },
                        base_url: { label: 'Адрес шлюза', type: 'text', class: 'gtsAIConn' },
                        // Ключ наружу не отдаётся ни виджету, ни в settings-ответе.
                        api_key: { label: 'Ключ', type: 'text', class: 'gtsAIConn' },
                        model: { label: 'Модель', type: 'text', class: 'gtsAIConn' },
                        active: { label: 'Включено', type: 'boolean', class: 'gtsAIConn' },
                        comment: { label: 'Примечание', type: 'textarea', class: 'gtsAIConn' },
                    },
                },
            },

            // Настройки виджета: имя, которое передаётся при вызове mixVue.
            gtsAISetting: {
                table: 'gtsAISetting',
                class: 'gtsAISetting',
                version: 7,
                authenticated: true,
                groups: 'Administrator',
                // ИИ правит собственные промпты. Отдельной проверки «только
                // админу» не нужно: таблица и так открыта только группе
                // Administrator, а права пользователя для ИИ - потолок.
                ai: 1,
                description: 'Настройки чатов ИИ: промпт, модель, права и лимиты. prompt - общий системный промпт; длинные правила лучше держать доппромптами (таблица gtsAIPrompt, поле setting_id).',
                active: true,
                properties: {
                    actions: {
                        read: { ai: 1 },
                        create: {},
                        update: { ai: 1 },
                        delete: {},
                        subtabs: {
                            prompts: {
                                gtsAIPrompt: {
                                    title: 'Доппромпты',
                                    table: 'gtsAIPrompt',
                                    where: { setting_id: 'id' },
                                },
                                gtsAISettingFile: {
                                    title: 'Файлы',
                                    table: 'gtsAISettingFile',
                                    where: { setting_id: 'id' },
                                },
                            },
                        },
                    },
                    // Правки от ИИ разрешены только с версионированием строки:
                    // иначе откатывать нечем.
                    save_version_row: true,
                    autocomplete: { tpl: '{$name}', limit: 0 },
                    fields: {
                        id: { type: 'view', class: 'gtsAISetting' },
                        name: { label: 'Имя настроек', type: 'text', class: 'gtsAISetting' },
                        title: { label: 'Заголовок чата', type: 'text', class: 'gtsAISetting' },
                        active: { label: 'Включено', type: 'boolean', class: 'gtsAISetting' },
                        conn_id: { label: 'Подключение', type: 'autocomplete', table: 'gtsAIConn', class: 'gtsAISetting' },
                        model: { label: 'Модель (если своя)', type: 'text', class: 'gtsAISetting' },
                        // Промпт длинный и в таблице раздвигал колонки: показываем
                        // первые 100 символов, полный текст - в подсказке и в форме.
                        prompt: { label: 'Системный промпт', type: 'textarea', truncate: 100, class: 'gtsAISetting' },
                        groups: { label: 'Группы MODX (через запятую)', type: 'text', class: 'gtsAISetting' },
                        allow_gtsapi: { label: 'Доступ к gtsAPI', type: 'boolean', class: 'gtsAISetting' },
                        allow_sql: { label: 'Доступ к SQL (только чтение)', type: 'boolean', class: 'gtsAISetting' },
                        // Что именно можно — в конфиге таблицы: actions: { update: {'ai': 1} }.
                        // Здесь общий рубильник: разрешена ли этому чату запись вообще.
                        allow_write: { label: 'Разрешить запись (что именно — в конфиге таблиц)', type: 'boolean', class: 'gtsAISetting' },
                        confirm_write: { label: 'Спрашивать подтверждение (по умолчанию)', type: 'boolean', class: 'gtsAISetting' },
                        confirm_lock: { label: 'Запретить выключать подтверждение в чате', type: 'boolean', class: 'gtsAISetting' },
                        // Рубильник на правку собственных промптов: мало открыть
                        // таблицы настроек, чат должен быть уполномочен явно.
                        allow_prompt_edit: { label: 'Разрешить править свои промпты', type: 'boolean', class: 'gtsAISetting' },
                        tables_allow: { label: 'Только эти таблицы (через запятую)', type: 'text', truncate: 60, class: 'gtsAISetting' },
                        tables_deny: { label: 'Запрещённые таблицы (через запятую)', type: 'text', truncate: 60, class: 'gtsAISetting' },
                        file_exts: { label: 'Расширения файлов (пусто — по умолчанию)', type: 'text', truncate: 60, class: 'gtsAISetting' },
                        save_history: { label: 'Сохранять историю', type: 'boolean', class: 'gtsAISetting' },
                        max_iterations: { label: 'Макс. шагов с инструментами', type: 'number', class: 'gtsAISetting' },
                        max_seconds: { label: 'Макс. секунд на ответ', type: 'number', class: 'gtsAISetting' },
                        max_tokens: { label: 'Макс. токенов в ответе', type: 'number', class: 'gtsAISetting' },
                        day_limit: { label: 'Лимит на юзера в день, $', type: 'decimal', FractionDigits: 2, class: 'gtsAISetting' },
                    },
                },
            },

            // Доппромпты — куски системного промпта с именами. Открыты ИИ на
            // чтение, создание и правку: ради этого всё и затевалось - чат
            // должен уметь записать правило, которое ему продиктовали.
            gtsAIPrompt: {
                table: 'gtsAIPrompt',
                class: 'gtsAIPrompt',
                version: 2,
                authenticated: true,
                groups: 'Administrator',
                ai: 1,
                description: 'Доппромпты: правила, которые модель подгружает по необходимости, как скиллы. setting_id - к какой настройке чата относится, name - имя правила, description - краткое «когда нужно» (только оно попадает в системный промпт), text - полный текст (читается инструментом read_prompt), active - показывать ли правило в списке, rank - порядок.',
                active: true,
                properties: {
                    actions: {
                        read: { ai: 1 },
                        create: { ai: 1 },
                        update: { ai: 1 },
                        delete: {},
                    },
                    save_version_row: true,
                    query: { sortby: { 'gtsAIPrompt.rank': 'ASC', 'gtsAIPrompt.id': 'ASC' } },
                    autocomplete: { tpl: '{$name}', limit: 0 },
                    fields: {
                        id: { type: 'view', class: 'gtsAIPrompt' },
                        setting_id: { label: 'Настройка чата', type: 'autocomplete', table: 'gtsAISetting', class: 'gtsAIPrompt' },
                        name: { label: 'Название', type: 'text', class: 'gtsAIPrompt' },
                        // Описание - единственное, что модель видит без запроса.
                        // Пишется как «когда это правило нужно», а не «о чём оно».
                        description: { label: 'Когда нужно (кратко)', type: 'text', class: 'gtsAIPrompt' },
                        text: { label: 'Текст', type: 'textarea', truncate: 100, class: 'gtsAIPrompt' },
                        active: { label: 'Включён', type: 'boolean', class: 'gtsAIPrompt' },
                        rank: { label: 'Порядок', type: 'number', class: 'gtsAIPrompt' },
                    },
                },
            },

            // Файлы, прикреплённые к настройке чата. Текст файла работает как
            // доппромпт: директору удобнее править правила в файле и вернуть
            // его обратно, чем печатать в поле формы.
            gtsAISettingFile: {
                table: 'gtsAISettingFile',
                class: 'gtsAISettingFile',
                version: 2,
                authenticated: true,
                groups: 'Administrator',
                ai: 1,
                description: 'Файлы, прикреплённые к настройке чата. setting_id - настройка, name - как правило называется, description - когда оно нужно, path - имя файла в хранилище чата, active - подставлять ли. Текст файла читается как доппромпт.',
                active: true,
                properties: {
                    actions: {
                        read: { ai: 1 },
                        create: {},
                        update: { ai: 1 },
                        delete: {},
                    },
                    save_version_row: true,
                    query: { sortby: { 'gtsAISettingFile.rank': 'ASC', 'gtsAISettingFile.id': 'ASC' } },
                    fields: {
                        id: { type: 'view', class: 'gtsAISettingFile' },
                        setting_id: { label: 'Настройка чата', type: 'autocomplete', table: 'gtsAISetting', class: 'gtsAISettingFile' },
                        name: { label: 'Название', type: 'text', class: 'gtsAISettingFile' },
                        description: { label: 'Когда нужно (кратко)', type: 'text', truncate: 80, class: 'gtsAISettingFile' },
                        // Выбор файла через медиа-браузер. Источник задаётся
                        // ИМЕНЕМ системной настройки: id источника на каждой
                        // площадке свой, и число здесь сломалось бы при переносе.
                        path: { label: 'Файл', type: 'file', mediaSource: 'gtsai_media_source', class: 'gtsAISettingFile' },
                        size: { label: 'Размер', type: 'number', readonly: 1, class: 'gtsAISettingFile' },
                        active: { label: 'Включён', type: 'boolean', class: 'gtsAISettingFile' },
                        rank: { label: 'Порядок', type: 'number', class: 'gtsAISettingFile' },
                        createdon: { label: 'Добавлен', type: 'date', readonly: 1, class: 'gtsAISettingFile' },
                    },
                },
            },

            // Чаты и сообщения — чтение для администратора (он видит все).
            gtsAIChat: {
                table: 'gtsAIChat',
                class: 'gtsAIChat',
                version: 1,
                authenticated: true,
                groups: 'Administrator',
                active: true,
                properties: {
                    actions: {
                        read: {},
                        delete: {},
                        subtabs: {
                            messages: {
                                gtsAIMessage: {
                                    title: 'Сообщения',
                                    table: 'gtsAIMessage',
                                    where: { chat_id: 'id' },
                                },
                            },
                        },
                    },
                    query: { sortby: { 'gtsAIChat.updatedon': 'DESC' } },
                    fields: {
                        id: { type: 'view', class: 'gtsAIChat' },
                        user_id: { label: 'Пользователь', type: 'autocomplete', table: 'modUser', class: 'gtsAIChat' },
                        setting: { label: 'Настройки', type: 'text', class: 'gtsAIChat' },
                        title: { label: 'Чат', type: 'text', class: 'gtsAIChat' },
                        page_url: { label: 'Страница', type: 'text', class: 'gtsAIChat' },
                        createdon: { label: 'Создан', type: 'date', readonly: 1, class: 'gtsAIChat' },
                        updatedon: { label: 'Обновлён', type: 'date', readonly: 1, class: 'gtsAIChat' },
                    },
                },
            },

            gtsAIMessage: {
                table: 'gtsAIMessage',
                class: 'gtsAIMessage',
                version: 1,
                authenticated: true,
                groups: 'Administrator',
                active: true,
                properties: {
                    actions: { read: {} },
                    fields: {
                        id: { type: 'view', class: 'gtsAIMessage' },
                        chat_id: { label: 'Чат', type: 'autocomplete', table: 'gtsAIChat', class: 'gtsAIMessage' },
                        role: { label: 'Роль', type: 'text', class: 'gtsAIMessage' },
                        content: { label: 'Текст', type: 'textarea', class: 'gtsAIMessage' },
                        tokens_in: { label: 'Токены вход', type: 'number', class: 'gtsAIMessage' },
                        tokens_out: { label: 'Токены выход', type: 'number', class: 'gtsAIMessage' },
                        batch_id: { label: 'Пакет правок', type: 'text', class: 'gtsAIMessage' },
                        createdon: { label: 'Когда', type: 'date', readonly: 1, class: 'gtsAIMessage' },
                    },
                },
            },

            // Лог вызовов: расход и отладка.
            gtsAILog: {
                table: 'gtsAILog',
                class: 'gtsAILog',
                version: 1,
                authenticated: true,
                groups: 'Administrator',
                active: true,
                properties: {
                    actions: { read: {} },
                    query: { sortby: { 'gtsAILog.id': 'DESC' } },
                    fields: {
                        id: { type: 'view', class: 'gtsAILog' },
                        createdon: { label: 'Когда', type: 'date', readonly: 1, class: 'gtsAILog' },
                        user_id: { label: 'Пользователь', type: 'autocomplete', table: 'modUser', class: 'gtsAILog' },
                        setting: { label: 'Настройки', type: 'text', class: 'gtsAILog' },
                        model: { label: 'Модель', type: 'text', class: 'gtsAILog' },
                        chat_id: { label: 'Чат', type: 'number', class: 'gtsAILog' },
                        tokens_in: { label: 'Вход', type: 'number', class: 'gtsAILog' },
                        tokens_out: { label: 'Выход', type: 'number', class: 'gtsAILog' },
                        cache_read: { label: 'Из кэша', type: 'number', class: 'gtsAILog' },
                        cost: { label: 'Стоимость, $', type: 'decimal', FractionDigits: 4, class: 'gtsAILog' },
                        ms: { label: 'мс', type: 'number', class: 'gtsAILog' },
                        iterations: { label: 'Шагов', type: 'number', class: 'gtsAILog' },
                        tools: { label: 'Инструменты', type: 'text', class: 'gtsAILog' },
                        status: { label: 'Итог', type: 'text', class: 'gtsAILog' },
                        error: { label: 'Ошибка', type: 'textarea', class: 'gtsAILog' },
                    },
                },
            },
        },
    },
}
