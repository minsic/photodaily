import { createRouter, createWebHistory } from 'vue-router'

import { useAuthStore } from '@/stores/auth'
import TimelineView from '@/views/TimelineView.vue'

declare module 'vue-router' {
  interface RouteMeta {
    /** Pagine raggiungibili senza essere autenticati. */
    guest?: boolean
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
      path: '/foto/:id(\\d+)',
      name: 'photo',
      component: () => import('@/views/PhotoView.vue'),
      props: (route) => ({ id: Number(route.params.id) }),
    },
    {
      path: '/foto/:id(\\d+)/modifica',
      name: 'photo-edit',
      component: () => import('@/views/EditPhotoView.vue'),
      props: (route) => ({ id: Number(route.params.id) }),
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

  if (!auth.ready) {
    await auth.restore()
  }

  if (to.meta.guest) {
    return to.name === 'login' && auth.isLoggedIn ? { name: 'timeline' } : true
  }

  return auth.isLoggedIn ? true : { name: 'login', query: { redirect: to.fullPath } }
})

export default router
