export default {
    name:"gtsAI",
    name_lower:"gtsai",
    version:"1.0.0",
    release:"beta",
    schema:true,
    assets:true,
    core:true,
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
