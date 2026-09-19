import { createPinia } from 'pinia'
import { createApp } from 'vue'

import App from './App.vue'
import { configureApi } from './api/client'
import router from './router'
import { useAuthStore } from './stores/auth'
import './style.css'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)

const auth = useAuthStore(pinia)

configureApi({
  token: () => auth.token,
  onUnauthorized: () => {
    auth.clear()

    const current = router.currentRoute.value

    if (current.name !== 'login') {
      void router.replace({ name: 'login', query: { redirect: current.fullPath } })
    }
  },
})

app.mount('#app')
