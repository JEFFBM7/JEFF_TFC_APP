import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import { router } from './router'
import { initDevCalendarStore } from './stores/devCalendar'

const app = createApp(App)
app.use(createPinia())
initDevCalendarStore()
app.use(router)
app.mount('#app')
