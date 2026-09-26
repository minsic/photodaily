import { computed, type ComputedRef } from 'vue'
import { useRoute, type RouteLocationRaw } from 'vue-router'

import { photos as photosApi, publicDiary as publicApi } from '@/api'
import type { Photo, Protagonist } from '@/api/types'
import { useAuthStore } from '@/stores/auth'
import { usePhotosStore } from '@/stores/photos'
import { usePublicDiaryStore } from '@/stores/publicDiary'

/**
 * Un diario visto da dentro (famiglia, con il login) o da fuori (sola
 * lettura, /pub/:slug). Lettore, vista Mese e slideshow parlano solo con
 * questa interfaccia: le differenze stanno qui.
 */
export interface Diary {
  kind: 'private' | 'public'
  slug: string | null
  /** Chi può caricare e modificare: mai chi guarda da fuori. */
  canUpload: boolean
  protagonist: Protagonist | null
  photo(id: string): Promise<Photo>
  /** Foto già in memoria (dalla timeline), da mostrare subito. */
  cached(id: string): Photo | undefined
  remove(id: string): Promise<void>
  /** Parametri comuni delle richieste (token del diario pubblico). */
  token: string | null
  to: {
    timeline(): RouteLocationRaw
    photo(id: string): RouteLocationRaw
    month(year: number, month: number): RouteLocationRaw
    year(year: number): RouteLocationRaw
  }
}

export function useDiary(): ComputedRef<Diary> {
  const route = useRoute()
  const auth = useAuthStore()
  const photos = usePhotosStore()
  const publicDiary = usePublicDiaryStore()

  return computed<Diary>(() => {
    const slug = typeof route.params.slug === 'string' ? route.params.slug : null

    if (slug === null) {
      return {
        kind: 'private',
        slug: null,
        canUpload: auth.isLoggedIn,
        protagonist: auth.family?.protagonist ?? null,
        token: null,
        photo: (id) => photosApi.get(id),
        cached: (id) => photos.find(id),
        remove: async (id) => {
          await photosApi.remove(id)
          photos.drop(id)
          await photos.loadYears().catch(() => undefined)
        },
        to: {
          timeline: () => ({ name: 'timeline' }),
          photo: (id) => ({ name: 'photo', params: { id } }),
          month: (year, month) => ({ name: 'month', params: { anno: year, mese: month } }),
          year: (year) => ({ name: 'year', params: { anno: year } }),
        },
      }
    }

    publicDiary.select(slug)

    return {
      kind: 'public',
      slug,
      canUpload: false,
      protagonist: publicDiary.protagonist,
      token: publicDiary.token,
      photo: (id) => publicApi.get(slug, publicDiary.token, id),
      cached: (id) => publicDiary.find(id),
      remove: () => Promise.reject(new Error('Il diario pubblico è in sola lettura.')),
      to: {
        timeline: () => ({ name: 'public-timeline', params: { slug } }),
        photo: (id) => ({ name: 'public-photo', params: { slug, id } }),
        month: (year, month) => ({ name: 'public-month', params: { slug, anno: year, mese: month } }),
        year: (year) => ({ name: 'public-year', params: { slug, anno: year } }),
      },
    }
  })
}
