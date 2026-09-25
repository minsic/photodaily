import { onBeforeUnmount, watch, type Ref } from 'vue'

/**
 * Chiama `loadMore` quando `sentinel` (un elemento in fondo alla lista) si
 * avvicina alla finestra: il margine fa partire la richiesta prima che si
 * arrivi davvero in fondo.
 *
 * L'observer avvisa solo quando la visibilità cambia: se dopo una pagina la
 * sentinella è ancora vicina (schermo alto, foto basse) si carica subito la
 * successiva, finché `canLoadMore` lo permette. Dopo un errore ci si ferma:
 * riprova chi scorre o chi tocca "Riprova".
 */
export function useInfiniteScroll(
  sentinel: Ref<HTMLElement | null>,
  loadMore: () => Promise<unknown>,
  canLoadMore: () => boolean,
): void {
  let visible = false
  let running = false

  async function run(): Promise<void> {
    if (running) {
      return
    }

    running = true

    try {
      while (visible && canLoadMore()) {
        await loadMore()
      }
    } finally {
      running = false
    }
  }

  const observer = new IntersectionObserver(
    (entries) => {
      visible = entries.some((entry) => entry.isIntersecting)

      if (visible) {
        void run()
      }
    },
    { rootMargin: '0px 0px 1200px 0px' },
  )

  // La sentinella compare e scompare con la lista (spinner, lista vuota).
  watch(
    sentinel,
    (element, previous) => {
      if (previous) {
        observer.unobserve(previous)
      }

      if (element) {
        observer.observe(element)
      }
    },
    { immediate: true },
  )

  onBeforeUnmount(() => observer.disconnect())
}
