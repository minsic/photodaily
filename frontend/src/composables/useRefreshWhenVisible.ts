import { onBeforeUnmount, onMounted } from 'vue'

/**
 * Esegue `refresh` ogni volta che la pagina torna in primo piano (cambio di
 * scheda, PWA riaperta dal telefono). Sta a `refresh` decidere se i dati sono
 * abbastanza vecchi da ricaricarli.
 */
export function useRefreshWhenVisible(refresh: () => unknown): void {
  function onVisibilityChange(): void {
    if (document.visibilityState === 'visible') {
      void refresh()
    }
  }

  onMounted(() => document.addEventListener('visibilitychange', onVisibilityChange))
  onBeforeUnmount(() => document.removeEventListener('visibilitychange', onVisibilityChange))
}
