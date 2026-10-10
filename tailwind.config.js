/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{vue,js,ts,jsx,tsx}",
  ],
  // Базовый сброс (preflight) приезжает один раз из pvtables.css. Раньше его
  // подключало каждое приложение, и на странице расчёта копий было три:
  // лишние килобайты и, что хуже, спор в каскаде — слой tailwind-base
  // объявлялся заново, и побеждала копия, загруженная последней.
  // Утилиты (flex, p-4, text-sm) остаются, отключается только сброс.
  corePlugins: {
    preflight: false,
  },
  theme: {
    extend: {},
  },
  plugins: [],
}

