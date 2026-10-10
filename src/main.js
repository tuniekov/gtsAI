import { createApp } from 'vue'
import './style.css'
import App from './App.vue'
import myPVTables from 'pvtables/dist/pvtables'

const app = createApp(App)
app.use(myPVTables)
app.mount('#gtsai')
