export default {
    name:"gtsAI",
    name_lower:"gtsai",
    version:"1.0.0",
    release:"beta",
    schema:true,
    assets:true,
    core:true,
    // ⚠️ Рабочие каталоги в пакет не кладём. Пакет собирается из каталога
    // компонента на сайте, и без этого списка в него попадали вложения чатов
    // с дева - то есть уезжали на чужие площадки вместе с кодом.
    exclude:["files", "cache"],
    // Попадёт в requires манифеста: MODX проверит это до распаковки.
    // Сам пакет gtsAPI дописывается туда из dependencies.js.
    requires:{ modx: ">=2.8", php: ">=7.4" },
    update:{
        snippets: true,
        plugins: true,
        // ⚠️ false намеренно. В настройках пакета лежат пароль читающего
        // пользователя базы и адрес соединения; при true каждая сборка
        // возвращала бы им пустые значения из конфига — ровно это и случилось
        // на деве 10.10.2026. Новые настройки при false всё равно создаются,
        // не меняются только уже заполненные.
        settings: false,
        menus: true,
        gtsapirules: true,
        gtsapipackages: true,
    }
}
