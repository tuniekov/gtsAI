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
    <a class="gtsai-promo-mark" href="https://gtsai.ru" target="_blank" rel="noopener" title="Открыть gtsai.ru">
      <img :src="logo" alt="gtsAI" width="40" height="40">
    </a>
    <div class="gtsai-promo-text">
      <div class="gtsai-promo-title">Шлюз gtsAI</div>
      <div class="gtsai-promo-sub">
        Через него чат ходит к моделям Claude. Один ключ на все приложения,
        подписка и оплата по балансу. Ключ вписывается во вкладке «Подключения».
      </div>
    </div>
    <a class="gtsai-promo-link" href="https://gtsai.ru" target="_blank" rel="noopener">
      Открыть gtsai.ru
    </a>
  </aside>
</template>

<script setup>
import { GtsAIDock } from 'pvtables/dist/pvtables'
// Логотип сервиса — файлом в сборке: Vite сам подставит правильный адрес,
// и путь не зависит от того, в какой папке стоит MODX.
import logo from './assets/gtsai-logo.svg'

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
  gap: 0.875rem;
  margin-top: 0.5rem;
  padding: 0.75rem 1rem;
  border: 1px solid var(--gts-line);
  border-radius: var(--gts-radius);
  background: var(--gts-surface-2);
  color: var(--gts-ink);
}
/* Логотип сервиса — собственная графика бренда, поэтому свои цвета у него
   законны; подложку под него не красим, он читается на любой схеме.
   Сам логотип тоже ведёт на сайт: так ожидают от логотипа. */
.gtsai-promo-mark {
  flex: none;
  display: block;
  line-height: 0;
  border-radius: var(--gts-radius-s);
}
.gtsai-promo-mark img {
  width: 40px;
  height: 40px;
  border-radius: var(--gts-radius-s);
  display: block;
}
.gtsai-promo-mark:hover { opacity: 0.85; }
.gtsai-promo-mark:focus-visible {
  outline: 2px solid var(--gts-accent);
  outline-offset: 2px;
}
.gtsai-promo-text { flex: 1; min-width: 0; }
.gtsai-promo-title {
  font-weight: var(--gts-weight-bold, 600);
  line-height: 1.2;
}
.gtsai-promo-sub {
  margin-top: 0.125rem;
  color: var(--gts-ink-2);
  font-size: var(--gts-size-sm);
  line-height: 1.35;
}
.gtsai-promo-link {
  flex: none;
  display: inline-flex;
  align-items: center;
  height: var(--gts-control-h);
  padding: 0 0.875rem;
  border: 1px solid var(--gts-accent);
  border-radius: var(--gts-radius-s);
  background: var(--gts-accent);
  color: var(--gts-accent-contrast);
  font-size: var(--gts-size-sm);
  text-decoration: none;
  white-space: nowrap;
}
.gtsai-promo-link:hover { background: var(--gts-accent-hover); border-color: var(--gts-accent-hover); }

/* Узкое окно: кнопка уезжает под текст, а не режет его. */
@media (max-width: 36rem) {
  .gtsai-promo { flex-wrap: wrap; }
  .gtsai-promo-text { flex-basis: 100%; order: 3; }
}
</style>
