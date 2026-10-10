<template>
  <!-- Сам чат и его размещение живут в PVTables (GtsAIChat + GtsAIDock): они
       есть на каждой странице, и встраивать их в другие компоненты можно без
       второго Vue-app. Здесь остаётся обёртка для вызова через mixVue. -->
  <GtsAIDock
    :setting="setting"
    :echo="echo"
    :default-dock="dock"
    :default-open="openByDefault"
    :launcher="launcher"
    :fill="fill"
  />
</template>

<script setup>
import { GtsAIDock } from 'pvtables/dist/pvtables'

// mixVue кладёт конфиг глобальным `let gtsaiConfigs` (не в window), поэтому
// читаем через typeof — иначе на странице без конфига будет ReferenceError.
const cfg = typeof gtsaiConfigs !== 'undefined' ? gtsaiConfigs : {}
const el = document.getElementById('gtsai')

// Имя настроек — ключ: промпт, права, модель и лимиты лежат в базе за ним.
const setting = cfg.setting || el?.dataset?.setting || 'default'
// Кнопка «Тест стрима» нужна на странице отладки, а не в рабочем окне.
const echo = !!cfg.echo
// Где показать в первый раз; дальше пользователь выбирает сам, выбор помнится.
const dock = cfg.dock || 'right'
// На странице админки чат встроен в поток и открыт сразу, запускатель не нужен.
const openByDefault = cfg.open !== undefined ? !!cfg.open : dock === 'inline'
const launcher = cfg.launcher !== undefined ? !!cfg.launcher : dock !== 'inline'
// На своей странице чат — её содержимое: во весь экран и без лишних
// заголовков. Внутри вкладок админки это выключено: там он один из блоков.
const fill = cfg.fill !== undefined ? !!cfg.fill : false
</script>
