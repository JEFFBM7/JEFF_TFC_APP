import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import { router } from './router'
import { initDevCalendarStore } from './stores/devCalendar'
import { initTheme } from './composables/useTheme'

initTheme() // applique le thème sauvegardé avant le mount (évite le flash)

const app = createApp(App)
app.use(createPinia())
initDevCalendarStore()
app.use(router)
app.mount('#app')
