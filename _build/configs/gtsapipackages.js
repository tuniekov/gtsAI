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
                version: 4,
                authenticated: true,
                groups: 'Administrator',
                active: true,
                properties: {
                    actions: { read: {}, create: {}, update: {}, delete: {} },
                    autocomplete: { tpl: '{$name}', limit: 0 },
                    fields: {
                        id: { type: 'view', class: 'gtsAISetting' },
                        name: { label: 'Имя настроек', type: 'text', class: 'gtsAISetting' },
                        title: { label: 'Заголовок чата', type: 'text', class: 'gtsAISetting' },
                        active: { label: 'Включено', type: 'boolean', class: 'gtsAISetting' },
                        conn_id: { label: 'Подключение', type: 'autocomplete', table: 'gtsAIConn', class: 'gtsAISetting' },
                        model: { label: 'Модель (если своя)', type: 'text', class: 'gtsAISetting' },
                        prompt: { label: 'Системный промпт', type: 'textarea', class: 'gtsAISetting' },
                        groups: { label: 'Группы MODX (через запятую)', type: 'text', class: 'gtsAISetting' },
                        allow_gtsapi: { label: 'Доступ к gtsAPI', type: 'boolean', class: 'gtsAISetting' },
                        allow_sql: { label: 'Доступ к SQL (только чтение)', type: 'boolean', class: 'gtsAISetting' },
                        // Что именно можно — в конфиге таблицы: actions: { update: {'ai': 1} }.
                        // Здесь общий рубильник: разрешена ли этому чату запись вообще.
                        allow_write: { label: 'Разрешить запись (что именно — в конфиге таблиц)', type: 'boolean', class: 'gtsAISetting' },
                        confirm_write: { label: 'Спрашивать подтверждение (по умолчанию)', type: 'boolean', class: 'gtsAISetting' },
                        confirm_lock: { label: 'Запретить выключать подтверждение в чате', type: 'boolean', class: 'gtsAISetting' },
                        save_history: { label: 'Сохранять историю', type: 'boolean', class: 'gtsAISetting' },
                        max_iterations: { label: 'Макс. шагов с инструментами', type: 'number', class: 'gtsAISetting' },
                        max_seconds: { label: 'Макс. секунд на ответ', type: 'number', class: 'gtsAISetting' },
                        day_limit: { label: 'Лимит на юзера в день, $', type: 'decimal', FractionDigits: 2, class: 'gtsAISetting' },
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
