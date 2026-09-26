import { createRouter, createWebHistory } from 'vue-router'

import { useAuthStore } from '@/stores/auth'
import { useSiteStore } from '@/stores/site'
import TimelineView from '@/views/TimelineView.vue'

declare module 'vue-router' {
  interface RouteMeta {
    /** Pagine raggiungibili senza essere autenticati. */
    guest?: boolean
    /** Pagine riservate agli amministratori della famiglia. */
    admin?: boolean
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
    },
    {
      path: '/carica',
      name: 'upload',
      component: () => import('@/views/UploadView.vue'),
    },
    {
      path: '/calendario',
      name: 'calendar',
      component: () => import('@/views/CalendarView.vue'),
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
      component: () => import('@/views/PhotoView.vue'),
      props: (route) => ({ id: String(route.params.id) }),
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
      meta: { guest: true },
    },
    {
      path: '/pub/:slug/foto/:id([0-9A-Za-z]{26})',
      name: 'public-photo',
      component: () => import('@/views/PublicPhotoView.vue'),
      props: (route) => ({ slug: String(route.params.slug), id: String(route.params.id) }),
      meta: { guest: true },
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/views/NotFoundView.vue'),
      meta: { guest: true },
    },
  ],
  scrollBehavior(_to, _from, savedPosition) {
    // Tornando indietro dalla foto si riprende il punto della timeline.
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

export default router
