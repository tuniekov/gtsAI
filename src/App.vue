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

  <!-- Про шлюз, через который чат и работает. Показываем в консоли компонента
       (меню Компоненты -> ИИ), где человек как раз заводит подключение и ищет,
       где взять ключ. На рабочих страницах выключено: там это шум. -->
  <aside v-if="promo" class="gtsai-promo">
    <div class="gtsai-promo-text">
      <b>Шлюз gtsAI</b> — через него чат ходит к моделям Claude.
      Один ключ на все приложения; подписка и оплата по балансу — на сайте
      сервиса. Ключ вписывается во вкладке «Подключения».
    </div>
    <a class="gtsai-promo-link" href="https://gtsai.ru" target="_blank" rel="noopener">gtsai.ru</a>
  </aside>
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
// Блок про шлюз — по умолчанию да: конфиг консоли компонента лежит в системной
// настройке, а она при установке НЕ перезаписывается (update.settings: false),
// то есть флаг туда не доедет. Поэтому выключаем там, где не нужно: на странице
// «Чат ИИ» (ai.tpl) и в расчёте, а консоль получает блок без правки настройки.
const promo = cfg.promo !== undefined ? !!cfg.promo : true
</script>

<style scoped>
/* Цвета только из токенов темы: блок должен читаться и в тёмной схеме. */
.gtsai-promo {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-top: 0.5rem;
  padding: 0.5rem 0.75rem;
  border: 1px solid var(--gts-line);
  border-radius: var(--gts-radius);
  background: var(--gts-accent-soft);
  color: var(--gts-ink);
  font-size: var(--gts-size-sm);
}
.gtsai-promo-text { flex: 1; min-width: 0; }
.gtsai-promo-link {
  flex: none;
  padding: 0.25rem 0.75rem;
  border: 1px solid var(--gts-accent);
  border-radius: var(--gts-radius-s);
  color: var(--gts-accent);
  text-decoration: none;
  white-space: nowrap;
}
.gtsai-promo-link:hover { background: var(--gts-accent); color: var(--gts-accent-contrast); }
</style>
