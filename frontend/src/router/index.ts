import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import type { UserRole } from '../types'

declare module 'vue-router' {
  interface RouteMeta {
    requiresAuth?: boolean
    requiresGuest?: boolean
    roles?: UserRole[]
    requiresGlobalAdmin?: boolean
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
        meta: { roles: ['admin'], requiresGlobalAdmin: true, title: 'Années scolaires' },
      },
      {
        path: 'school-years/:id/classrooms/:classroomId',
        name: 'school-year-class-detail',
        component: () => import('../views/SchoolYearClassDetailView.vue'),
        meta: { roles: ['admin'], title: 'Détail classe' },
        props: true,
      },
      {
        path: 'school-years/:id',
        name: 'school-year-detail',
        component: () => import('../views/SchoolYearDetailView.vue'),
        meta: { roles: ['admin'], requiresGlobalAdmin: true, title: 'Détail année scolaire' },
        props: true,
      },
      {
        path: 'classes',
        name: 'levels',
        component: () => import('../views/LevelsView.vue'),
        meta: { roles: ['admin'], title: 'Classes' },
      },
      {
        path: 'levels',
        redirect: { name: 'levels' },
      },
      {
        path: 'classes/:id',
        name: 'level-detail',
        component: () => import('../views/LevelDetailView.vue'),
        meta: { roles: ['admin'], title: 'Classes' },
        props: true,
      },
      {
        path: 'levels/:id',
        redirect: (to) => ({ name: 'level-detail', params: { id: to.params.id } }),
      },
      {
        path: 'users',
        name: 'users',
        component: () => import('../views/UsersView.vue'),
        meta: { roles: ['admin'], requiresGlobalAdmin: true, title: 'Utilisateurs' },
      },
      {
        path: 'secondary-admins',
        name: 'secondary-admins',
        component: () => import('../views/SecondaryAdminsView.vue'),
        meta: { roles: ['admin'], requiresGlobalAdmin: true, title: 'Admins secondaires' },
      },
      {
        path: 'cours',
        name: 'subjects',
        component: () => import('../views/SubjectsView.vue'),
        meta: { roles: ['admin'], title: 'Cours' },
      },
      {
        path: 'subjects',
        redirect: { name: 'subjects' },
      },
      {
        path: 'teachers',
        name: 'teachers',
        component: () => import('../views/TeachersView.vue'),
        meta: { roles: ['admin'], title: 'Enseignants' },
      },
      {
        path: 'teachers/:id',
        name: 'teacher-detail',
        component: () => import('../views/TeacherDetailView.vue'),
        meta: { roles: ['admin'], title: 'Fiche enseignant' },
        props: true,
      },
      {
        path: 'parents',
        name: 'parents',
        component: () => import('../views/ParentsView.vue'),
        meta: { roles: ['admin'], title: 'Parents' },
      },
      {
        path: 'parents/:id',
        name: 'parent-detail',
        component: () => import('../views/ParentDetailView.vue'),
        meta: { roles: ['admin'], title: 'Fiche parent' },
        props: true,
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
        path: 'students-at-risk',
        name: 'students-at-risk',
        component: () => import('../views/StudentsAtRiskView.vue'),
        meta: { roles: ['admin', 'enseignant'], title: 'Élèves en difficulté' },
      },
      {
        path: 'settings',
        name: 'settings',
        component: () => import('../views/SettingsView.vue'),
        meta: { roles: ['admin'], requiresGlobalAdmin: true, title: 'Paramètres & seuils' },
      },
      {
        path: 'messages',
        name: 'messages',
        component: () => import('../views/MessagesView.vue'),
        meta: { requiresAuth: true, title: 'Messagerie' },
      },
      // ── Portail élève ──
      {
        path: 'student',
        name: 'student-dashboard',
        component: () => import('../views/StudentDashboardView.vue'),
        meta: { roles: ['eleve'], title: 'Tableau de bord' },
      },
      {
        path: 'student/bulletin',
        name: 'student-bulletin',
        component: () => import('../views/StudentBulletinView.vue'),
        meta: { roles: ['eleve'], title: 'Mon bulletin' },
      },
      {
        path: 'student/absences',
        name: 'student-absences',
        component: () => import('../views/StudentAbsencesView.vue'),
        meta: { roles: ['eleve'], title: 'Mes absences' },
      },
      {
        path: 'student/timetable',
        name: 'student-timetable',
        component: () => import('../views/StudentTimetableView.vue'),
        meta: { roles: ['eleve'], title: 'Mon emploi du temps' },
      },
      {
        path: 'student/profile',
        name: 'student-profile',
        component: () => import('../views/PortalProfileView.vue'),
        meta: { roles: ['eleve'], title: 'Mon profil' },
      },
      // ── Portail parent ──
      {
        path: 'parent',
        name: 'parent-dashboard',
        component: () => import('../views/ParentDashboardView.vue'),
        meta: { roles: ['parent'], title: 'Tableau de bord' },
      },
      {
        path: 'parent/profile',
        name: 'parent-profile',
        component: () => import('../views/PortalProfileView.vue'),
        meta: { roles: ['parent'], title: 'Mon profil' },
      },
      {
        path: 'parent/children',
        name: 'parent-children',
        component: () => import('../views/ParentChildrenView.vue'),
        meta: { roles: ['parent'], title: 'Mes enfants' },
      },
      {
        path: 'parent/children/:id',
        alias: 'parent/child/:id',
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
    if (auth.user?.role === 'parent') return { name: 'parent-dashboard' }
    if (auth.user?.role === 'eleve') return { name: 'student-dashboard' }
    return { name: 'dashboard' }
  }

  if (auth.isAuthenticated && (to.name === 'dashboard' || to.path === '/')) {
    if (auth.user?.role === 'eleve') return { name: 'student-dashboard' }
    if (auth.user?.role === 'parent') return { name: 'parent-dashboard' }
  }

  if (to.meta.roles && to.meta.roles.length > 0) {
    if (!auth.user || !to.meta.roles.includes(auth.user.role)) {
      return { name: 'forbidden' }
    }
  }

  if (to.meta.requiresGlobalAdmin) {
    const isGlobalAdmin = auth.user?.role === 'admin' && (auth.user.admin_scope ?? 'global') === 'global'
    if (!isGlobalAdmin) {
      return { name: 'forbidden' }
    }
  }

  return true
})

router.afterEach((to) => {
  const base = 'EduConnect'
  document.title = to.meta.title ? `${to.meta.title} · ${base}` : base
})
