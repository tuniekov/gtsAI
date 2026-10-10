-- =============================================================================
--  gtsAI — пользователь базы «только чтение» для чата ИИ.
--
--  Выполнить ОДИН РАЗ от администратора MySQL (root). Скрипт сам:
--    1) заведёт пользователя с правом SELECT и только на вашу базу;
--    2) ограничит его 200 запросами в час;
--    3) пропишет логин и пароль в системные настройки MODX.
--
--  Больше ничего настраивать не нужно: адрес базы чат берёт тот же, которым
--  ходит сам сайт. Кэш чистить тоже не нужно — эти настройки читаются напрямую.
--
--  Как запустить:
--    mysql -u root -p < sql_readonly.sql
--  либо целиком вставить в phpMyAdmin → SQL (вкладка, а не окно поиска).
-- =============================================================================

-- ─────────── ЗАПОЛНИТЬ ТРИ СТРОКИ И БОЛЬШЕ НИЧЕГО ───────────
SET @db     = 'modx28';                -- имя базы сайта: core/config/config.inc.php, $database_name
SET @prefix = 'modx_';                 -- префикс таблиц оттуда же, $table_prefix
SET @pass   = 'ПРИДУМАЙТЕ_ПАРОЛЬ';     -- пароль нового пользователя, свой
-- ─────────────────────────────────────────────────────────────

SET @user = 'gtsai_ro';

-- Пользователь заводится на два адреса: '%' — когда сайт идёт к базе по сети
-- (так на OSPanel), 'localhost' — когда через сокет (обычно на Linux). Какой
-- из них сработает, зависит от сервера, поэтому делаем оба и не гадаем.

-- 1. Создать (или оставить как есть) и задать пароль.
SET @s = CONCAT('CREATE USER IF NOT EXISTS ''', @user, '''@''%'' IDENTIFIED BY ''', @pass, '''');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = CONCAT('CREATE USER IF NOT EXISTS ''', @user, '''@''localhost'' IDENTIFIED BY ''', @pass, '''');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

-- Пароль задаём отдельно: если пользователь уже был, CREATE его не меняет.
SET @s = CONCAT('ALTER USER ''', @user, '''@''%'' IDENTIFIED BY ''', @pass, '''');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = CONCAT('ALTER USER ''', @user, '''@''localhost'' IDENTIFIED BY ''', @pass, '''');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

-- 2. Право ровно одно и ровно на одну базу.
--    FILE не выдаём никогда: с ним SELECT умеет писать файлы на диск сервера
--    (INTO OUTFILE) и читать их (LOAD_FILE).
SET @s = CONCAT('GRANT SELECT ON `', @db, '`.* TO ''', @user, '''@''%''');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = CONCAT('GRANT SELECT ON `', @db, '`.* TO ''', @user, '''@''localhost''');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

-- 3. Потолок нагрузки: больше 200 запросов в час одному чату незачем.
SET @s = CONCAT('ALTER USER ''', @user, '''@''%'' WITH MAX_QUERIES_PER_HOUR 200');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
SET @s = CONCAT('ALTER USER ''', @user, '''@''localhost'' WITH MAX_QUERIES_PER_HOUR 200');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

FLUSH PRIVILEGES;

-- 4. Прописать доступы в настройки MODX.
SET @s = CONCAT('INSERT INTO `', @db, '`.`', @prefix, 'system_settings` (`key`,`value`,`xtype`,`namespace`,`area`) ',
                'VALUES (''gtsai_sql_user'', ''', @user, ''', ''textfield'', ''gtsai'', ''gtsai_sql'') ',
                'ON DUPLICATE KEY UPDATE `value` = ''', @user, '''');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @s = CONCAT('INSERT INTO `', @db, '`.`', @prefix, 'system_settings` (`key`,`value`,`xtype`,`namespace`,`area`) ',
                'VALUES (''gtsai_sql_password'', ''', @pass, ''', ''text-password'', ''gtsai'', ''gtsai_sql'') ',
                'ON DUPLICATE KEY UPDATE `value` = ''', @pass, '''');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

-- 5. Показать, что получилось. Ожидаем ровно две строки прав на пользователя:
--    USAGE ON *.* и SELECT ON `ваша база`.*. Если видно что-то ещё — лишнее.
SET @s = CONCAT('SHOW GRANTS FOR ''', @user, '''@''%''');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
