import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import type { UserRole } from '../types'

declare module 'vue-router' {
  interface RouteMeta {
    requiresAuth?: boolean
    requiresGuest?: boolean
    roles?: UserRole[]
    title?: string
  }
}

const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'login',
    component: () => import('../views/LoginView.vue'),
    meta: { requiresGuest: true, title: 'Connexion' },
  },
  {
    path: '/',
    component: () => import('../layouts/AdminLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'dashboard',
        component: () => import('../views/DashboardView.vue'),
        meta: { title: 'Tableau de bord' },
      },
      {
        path: 'school-years',
        name: 'school-years',
        component: () => import('../views/SchoolYearsView.vue'),
        meta: { roles: ['admin'], title: 'Années scolaires' },
      },
      {
        path: 'school-years/:id',
        name: 'school-year-detail',
        component: () => import('../views/SchoolYearDetailView.vue'),
        meta: { roles: ['admin'], title: 'Détail année scolaire' },
        props: true,
      },
      {
        path: 'levels',
        name: 'levels',
        component: () => import('../views/LevelsView.vue'),
        meta: { roles: ['admin'], title: 'Niveaux scolaires' },
      },
      {
        path: 'levels/:id',
        name: 'level-detail',
        component: () => import('../views/LevelDetailView.vue'),
        meta: { roles: ['admin'], title: 'Classes d\'un niveau' },
        props: true,
      },
      {
        path: 'subjects',
        name: 'subjects',
        component: () => import('../views/SubjectsView.vue'),
        meta: { roles: ['admin'], title: 'Matières' },
      },
      {
        path: 'teachers',
        name: 'teachers',
        component: () => import('../views/TeachersView.vue'),
        meta: { roles: ['admin'], title: 'Enseignants' },
      },
    ],
  },
  {
    path: '/403',
    name: 'forbidden',
    component: () => import('../views/ForbiddenView.vue'),
    meta: { title: 'Accès refusé' },
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('../views/NotFoundView.vue'),
    meta: { title: 'Page introuvable' },
  },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.initialized) {
    await auth.init()
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.meta.requiresGuest && auth.isAuthenticated) {
    return { name: 'dashboard' }
  }

  if (to.meta.roles && to.meta.roles.length > 0) {
    if (!auth.user || !to.meta.roles.includes(auth.user.role)) {
      return { name: 'forbidden' }
    }
  }

  return true
})

router.afterEach((to) => {
  const base = 'EduConnect'
  document.title = to.meta.title ? `${to.meta.title} · ${base}` : base
})
