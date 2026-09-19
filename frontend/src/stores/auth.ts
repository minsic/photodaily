import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { auth as authApi } from '@/api'
import type { User } from '@/api/types'

const TOKEN_KEY = 'photodaily.token'

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(localStorage.getItem(TOKEN_KEY))
  const user = ref<User | null>(null)
  /** Diventa true quando il token salvato è stato verificato all'avvio. */
  const ready = ref(false)

  const isLoggedIn = computed(() => token.value !== null)
  const isAdmin = computed(() => user.value?.role === 'admin')
  const family = computed(() => user.value?.family ?? null)

  async function login(email: string, password: string): Promise<void> {
    const response = await authApi.login(email, password)

    setToken(response.token)
    user.value = response.user
    ready.value = true
  }

  /** All'avvio ricontrolla il token salvato: se non vale più, si riparte puliti. */
  async function restore(): Promise<void> {
    if (!token.value) {
      ready.value = true

      return
    }

    try {
      user.value = await authApi.me()
    } catch {
      clear()
    } finally {
      ready.value = true
    }
  }

  async function logout(): Promise<void> {
    if (token.value) {
      await authApi.logout().catch(() => undefined)
    }

    clear()
  }

  function setToken(value: string): void {
    token.value = value
    localStorage.setItem(TOKEN_KEY, value)
  }

  function clear(): void {
    token.value = null
    user.value = null
    localStorage.removeItem(TOKEN_KEY)
  }

  return { token, user, ready, isLoggedIn, isAdmin, family, login, restore, logout, clear }
})
