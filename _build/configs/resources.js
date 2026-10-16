/**
 * Страницы пакета — по сайтам.
 *
 * Ключ верхнего уровня — метка сайта из системной настройки `gtsapi_site_key`
 * ('modx28', 'vk24'). Родитель и шаблон у каждого сайта свои, поэтому пишутся
 * прямо здесь: адреса в код не попадают, а привязка остаётся явной.
 *
 * `config_name` — имя системной настройки, в которой резолвер хранит id созданной
 * страницы (чтобы при переустановке не плодить дубли), а НЕ конфиг приложения.
 * Что на странице отрисовать, задаётся файлом содержимого
 * `core/components/gtsai/elements/resources/web/ai.tpl` — вызов mixVue.
 */
export default {
    'modx28': {
        'web': {
            'ai-nastroyki': {
                'pagetitle': 'Настройки ИИ',
                'published': true,
                'hidemenu': false,
                'config_name': 'gtsai_p_settings',
                'parent': 248,
                'properties': { 'templatename': 'adminTemplate' },
                'groups': 'administrator',
            },
            'ai': {
                'pagetitle': 'Чат ИИ',
                'published': true,
                'hidemenu': false,
                'config_name': 'gtsai_p_chat',
                'parent': 248,                                    // Администратор
                'properties': { 'templatename': 'adminTemplate' },
                // Чат ходит в данные от имени пользователя, поэтому страница —
                // для администраторов, как и журнал запросов.
                'groups': 'administrator',
            },
        },
    },

    'vk24': {
        'web': {
            // Страница верхнего меню: общий чат для всех сотрудников.
            // Лежит в корне (parent 0) и не скрыта - только так она попадает
            // в верхнее меню сайта. Группы ресурсов не назначаем: страница
            // открыта всем, а кто что увидит в данных, решают права
            // пользователя - сам чат без входа на сайт не работает.
            'ai-chat': {
                'pagetitle': 'ИИ',
                'published': true,
                'hidemenu': false,
                'config_name': 'gtsai_p_chat_all',
                'parent': 0,
                'properties': { 'templatename': 'Логистика' },
            },
            'ai-nastroyki': {
                'pagetitle': 'Настройки ИИ',
                'published': true,
                'hidemenu': false,
                'config_name': 'gtsai_p_settings',
                'parent': 197,
                'properties': { 'templatename': 'Логистика' },
            },
            'ai': {
                'pagetitle': 'Чат ИИ',
                'published': true,
                'hidemenu': false,
                'config_name': 'gtsai_p_chat',
                'parent': 197,                                    // Администратор
                'properties': { 'templatename': 'Логистика' },
            },
        },
    },

    // Незнакомый сайт страниц не получает: плодить их в корне пакет не должен.
}
