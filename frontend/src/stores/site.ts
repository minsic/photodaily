import { defineStore } from 'pinia'
import { ref } from 'vue'

import { site as siteApi } from '@/api'
import { ApiError } from '@/api/client'
import type { SiteFamily } from '@/api/types'

/**
 * Di quale diario è l'indirizzo da cui è aperta l'app.
 *
 * - `main`: indirizzo principale del servizio, nessuna famiglia in particolare.
 * - `family`: <slug>.photodaily.app o il dominio proprio di una famiglia.
 * - `unknown`: l'API non riconosce l'host (sottodominio inesistente).
 *
 * Se l'API non risponde si prosegue come sull'indirizzo principale:
 * le chiamate successive mostreranno il loro errore.
 */
export const useSiteStore = defineStore('site', () => {
  const kind = ref<'main' | 'family' | 'unknown'>('main')
  const family = ref<SiteFamily | null>(null)
  const ready = ref(false)

  async function load(): Promise<void> {
    try {
      family.value = await siteApi.get()
      kind.value = family.value ? 'family' : 'main'
    } catch (error) {
      if (error instanceof ApiError && error.status === 404) {
        kind.value = 'unknown'
      }
    } finally {
      ready.value = true
    }
  }

  return { kind, family, ready, load }
})
