import { defineStore } from 'pinia'
import { ref, shallowRef } from 'vue'
import type { RouteLocationNormalizedLoaded } from 'vue-router'

/**
 * Il lettore a schermo intero si apre sopra la vista da cui arriva: qui si
 * ricorda quale (percorso completo), così App.vue la tiene montata sotto e
 * il tasto indietro ritrova la stessa posizione di scroll. Il percorso viaggia
 * anche nello stato della cronologia (history.state.backdrop), per il
 * ricaricamento della pagina e per avanti/indietro.
 */
export const useReaderStore = defineStore('reader', () => {
  const backdrop = ref<string | null>(null)
  /** La stessa vista, risolta e con i componenti già scaricati: la usa App.vue. */
  const backdropRoute = shallowRef<RouteLocationNormalizedLoaded | null>(null)

  return { backdrop, backdropRoute }
})
