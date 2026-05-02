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
    path: '/forgot-password',
    name: 'forgot-password',
    component: () => import('../views/ForgotPasswordView.vue'),
    meta: { requiresGuest: true, title: 'Mot de passe oublié' },
  },
  {
    path: '/reset-password',
    name: 'reset-password',
    component: () => import('../views/ResetPasswordView.vue'),
    meta: { requiresGuest: true, title: 'Réinitialiser le mot de passe' },
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
        path: 'users',
        name: 'users',
        component: () => import('../views/UsersView.vue'),
        meta: { roles: ['admin'], title: 'Utilisateurs' },
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
      {
        path: 'parents',
        name: 'parents',
        component: () => import('../views/ParentsView.vue'),
        meta: { roles: ['admin'], title: 'Parents' },
      },
      {
        path: 'students',
        name: 'students',
        component: () => import('../views/StudentsView.vue'),
        meta: { roles: ['admin'], title: 'Élèves' },
      },
      {
        path: 'students/:id',
        name: 'student-detail',
        component: () => import('../views/StudentDetailView.vue'),
        meta: { roles: ['admin'], title: 'Fiche élève' },
        props: true,
      },
      {
        path: 'students/:id/report-card',
        name: 'report-card',
        component: () => import('../views/ReportCardView.vue'),
        meta: { roles: ['admin', 'enseignant'], title: 'Bulletin' },
        props: true,
      },
      {
        path: 'evaluations',
        name: 'evaluations',
        component: () => import('../views/EvaluationsView.vue'),
        meta: { roles: ['admin', 'enseignant'], title: 'Évaluations' },
      },
      {
        path: 'evaluations/:id/grades',
        name: 'grade-entry',
        component: () => import('../views/GradeEntryView.vue'),
        meta: { roles: ['admin', 'enseignant'], title: 'Saisie des notes' },
        props: true,
      },
      {
        path: 'attendances',
        name: 'attendances',
        component: () => import('../views/AttendancesView.vue'),
        meta: { roles: ['admin', 'enseignant', 'secretariat'], title: 'Présences & absences' },
      },
      {
        path: 'timetable',
        name: 'timetable',
        component: () => import('../views/TimetableView.vue'),
        meta: { roles: ['admin', 'enseignant', 'secretariat'], title: 'Emploi du temps' },
      },
      {
        path: 'reports',
        name: 'reports',
        component: () => import('../views/ReportsView.vue'),
        meta: { roles: ['admin', 'enseignant'], title: 'Rapports & exports' },
      },
      {
        path: 'messages',
        name: 'messages',
        component: () => import('../views/MessagesView.vue'),
        meta: { requiresAuth: true, title: 'Messagerie' },
      },
      // ── Portail parent ──
      {
        path: 'parent',
        name: 'parent-dashboard',
        component: () => import('../views/ParentDashboardView.vue'),
        meta: { roles: ['parent'], title: 'Espace parent' },
      },
      {
        path: 'parent/child/:id',
        name: 'parent-child',
        component: () => import('../views/ParentChildView.vue'),
        meta: { roles: ['parent'], title: 'Suivi enfant' },
        props: true,
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
    return auth.user?.role === 'parent'
      ? { name: 'parent-dashboard' }
      : { name: 'dashboard' }
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
