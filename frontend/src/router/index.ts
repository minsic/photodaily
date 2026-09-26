import { createRouter, createWebHistory, loadRouteLocation, START_LOCATION } from 'vue-router'

import { useAuthStore } from '@/stores/auth'
import { useReaderStore } from '@/stores/reader'
import { useSiteStore } from '@/stores/site'
import TimelineView from '@/views/TimelineView.vue'

declare module 'vue-router' {
  interface RouteMeta {
    /** Pagine raggiungibili senza essere autenticati. */
    guest?: boolean
    /** Pagine riservate agli amministratori della famiglia. */
    admin?: boolean
    /** Il lettore a schermo intero: si apre sopra la vista precedente. */
    reader?: boolean
    /** Viste sopra cui si può aprire il lettore (restano montate sotto). */
    backdrop?: boolean
  }
}

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { guest: true },
    },
    {
      path: '/',
      name: 'timeline',
      component: TimelineView,
      meta: { backdrop: true },
    },
    {
      path: '/carica',
      name: 'upload',
      component: () => import('@/views/UploadView.vue'),
    },
    {
      // Il calendario del giro precedente è diventato la vista Anno.
      path: '/calendario',
      name: 'calendar',
      redirect: () => ({ name: 'year', params: { anno: new Date().getFullYear() } }),
    },
    {
      path: '/anno/:anno([0-9]{4})',
      name: 'year',
      component: () => import('@/views/YearView.vue'),
      props: (route) => ({ anno: Number(route.params.anno) }),
    },
    {
      path: '/mese/:anno([0-9]{4})/:mese([0-9]{1,2})',
      name: 'month',
      component: () => import('@/views/MonthView.vue'),
      props: (route) => ({ anno: Number(route.params.anno), mese: Number(route.params.mese) }),
      meta: { backdrop: true },
    },
    {
      path: '/profilo',
      name: 'profile',
      component: () => import('@/views/ProfileView.vue'),
    },
    {
      path: '/recupera',
      name: 'recover',
      component: () => import('@/views/RecoverView.vue'),
    },
    {
      // L'id della foto è un ULID: i vecchi link numerici finiscono nel 404.
      path: '/foto/:id([0-9A-Za-z]{26})',
      name: 'photo',
      component: () => import('@/views/ReaderView.vue'),
      props: (route) => ({ id: String(route.params.id) }),
      meta: { reader: true },
    },
    {
      path: '/foto/:id([0-9A-Za-z]{26})/modifica',
      name: 'photo-edit',
      component: () => import('@/views/EditPhotoView.vue'),
      props: (route) => ({ id: String(route.params.id) }),
    },
    {
      path: '/impostazioni',
      name: 'settings',
      component: () => import('@/views/SettingsView.vue'),
      meta: { admin: true },
    },
    {
      // "/invito/..." resta valido per i link già mandati per email.
      path: '/invite/:token',
      alias: '/invito/:token',
      name: 'accept-invite',
      component: () => import('@/views/AcceptInviteView.vue'),
      props: true,
      meta: { guest: true },
    },
    {
      path: '/pub/:slug',
      name: 'public-timeline',
      component: () => import('@/views/PublicTimelineView.vue'),
      props: true,
      meta: { guest: true, backdrop: true },
    },
    {
      path: '/pub/:slug/mese/:anno([0-9]{4})/:mese([0-9]{1,2})',
      name: 'public-month',
      component: () => import('@/views/MonthView.vue'),
      props: (route) => ({ anno: Number(route.params.anno), mese: Number(route.params.mese) }),
      meta: { guest: true, backdrop: true },
    },
    {
      path: '/pub/:slug/anno/:anno([0-9]{4})',
      name: 'public-year',
      component: () => import('@/views/YearView.vue'),
      props: (route) => ({ anno: Number(route.params.anno) }),
      meta: { guest: true },
    },
    {
      path: '/pub/:slug/foto/:id([0-9A-Za-z]{26})',
      name: 'public-photo',
      component: () => import('@/views/ReaderView.vue'),
      props: (route) => ({ id: String(route.params.id) }),
      meta: { guest: true, reader: true },
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/views/NotFoundView.vue'),
      meta: { guest: true },
    },
  ],
  scrollBehavior(to, from, savedPosition) {
    // Il lettore si apre e si chiude sopra la vista, che resta dov'era.
    if (to.meta.reader || (from.meta.reader && useReaderStore().backdrop === to.fullPath)) {
      return false
    }

    return savedPosition ?? { top: 0 }
  },
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  const site = useSiteStore()

  await Promise.all([site.ready || site.load(), auth.ready || auth.restore()])

  // Sottodominio che non corrisponde a nessuna famiglia.
  if (site.kind === 'unknown') {
    return to.name === 'not-found'
      ? true
      : { name: 'not-found', params: { pathMatch: to.path.split('/').filter(Boolean) } }
  }

  // Sull'indirizzo di un diario pubblico (o con password) chi non ha un
  // account vede il diario in sola lettura invece del login.
  if (to.name === 'timeline' && !auth.isLoggedIn && site.family && site.family.access_mode !== 'private') {
    return { name: 'public-timeline', params: { slug: site.family.slug } }
  }

  if (to.meta.guest) {
    return to.name === 'login' && auth.isLoggedIn ? { name: 'timeline' } : true
  }

  if (!auth.isLoggedIn) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  // Le impostazioni famiglia sono solo per gli admin: l'API le rifiuterebbe
  // comunque, ma così non si mostra una pagina che non si può usare.
  return to.meta.admin && !auth.isAdmin ? { name: 'timeline' } : true
})

/**
 * Vista da tenere sotto il lettore: quella da cui lo si apre (se è una delle
 * viste con meta.backdrop). Passando da una foto all'altra resta la stessa;
 * ricaricando la pagina la si ritrova nello stato della cronologia.
 */
router.beforeEach(async (to, from) => {
  if (!to.meta.reader) {
    return true
  }

  const reader = useReaderStore()

  if (from === START_LOCATION) {
    const saved = (window.history.state as { backdrop?: unknown } | null)?.backdrop
    reader.backdrop = typeof saved === 'string' ? saved : null
  } else if (!from.meta.reader) {
    reader.backdrop = from.meta.backdrop ? from.fullPath : null
  }

  const backdrop = reader.backdrop ? router.resolve(reader.backdrop) : null

  if (backdrop === null || backdrop.matched.length === 0 || !backdrop.meta.backdrop) {
    reader.backdrop = null
    reader.backdropRoute = null
  } else if (reader.backdropRoute?.fullPath !== backdrop.fullPath) {
    // Dopo un ricaricamento il componente della vista sotto non è ancora scaricato.
    reader.backdropRoute = await loadRouteLocation(backdrop)
  }

  return true
})

router.afterEach((to) => {
  if (to.meta.reader) {
    window.history.replaceState({ ...window.history.state, backdrop: useReaderStore().backdrop }, '')
  }
})

export default router
