import { onBeforeUnmount, onMounted } from 'vue'

/**
 * Tiene `robots: noindex, nofollow` sulle pagine pubbliche finché sono aperte:
 * un diario di famiglia condiviso per link non deve finire nei motori di ricerca.
 */
export function useNoIndex(): void {
  let previous: string | null = null
  let created = false

  onMounted(() => {
    let meta = document.querySelector<HTMLMetaElement>('meta[name="robots"]')

    if (!meta) {
      meta = document.createElement('meta')
      meta.name = 'robots'
      document.head.appendChild(meta)
      created = true
    } else {
      previous = meta.content
    }

    meta.content = 'noindex, nofollow'
  })

  onBeforeUnmount(() => {
    const meta = document.querySelector<HTMLMetaElement>('meta[name="robots"]')

    if (!meta) {
      return
    }

    if (created) {
      meta.remove()
    } else if (previous !== null) {
      meta.content = previous
    }
  })
}
