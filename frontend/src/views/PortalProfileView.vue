<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import {
  AlertTriangle,
  CalendarDays,
  ChevronRight,
  FileText,
  LogOut,
  Mail,
  Users,
  GraduationCap,
  User,
} from 'lucide-vue-next'
import { api, ApiError } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { usePortalDashboard } from '../composables/usePortalDashboard'
import type { Student } from '../types'

const auth = useAuthStore()
const router = useRouter()
const { initials } = usePortalDashboard()

const loading = ref(false)
const loggingOut = ref(false)
const error = ref('')
const studentProfile = ref<Student | null>(null)
const children = ref<Array<{ id: number; full_name: string; classroom?: { full_name?: string } }>>([])

const isStudent = computed(() => auth.hasRole('eleve'))
const isParent = computed(() => auth.hasRole('parent'))

const displayName = computed(() => {
  if (isStudent.value && studentProfile.value?.full_name) {
    return studentProfile.value.full_name
  }
  return auth.user?.name?.trim() || 'Utilisateur'
})

const roleLabel = computed(() => {
  if (isStudent.value) return 'Élève'
  if (isParent.value) return 'Parent'
  return 'Utilisateur'
})

const classroomLabel = computed(() => {
  const room = studentProfile.value?.classroom
  if (!room) return null
  return room.full_name ?? null
})

const childCountLabel = computed(() => {
  const n = children.value.length
  if (n === 0) return 'Aucun enfant rattaché'
  if (n === 1) return '1 enfant rattaché'
  return `${n} enfants rattachés`
})

interface ProfileLink {
  label: string
  description: string
  to: { name: string }
  icon: typeof FileText
}

const studentLinks = computed<ProfileLink[]>(() => {
  if (!isStudent.value) return []
  return [
    { label: 'Mon bulletin', description: 'Notes et relevé de période', to: { name: 'student-bulletin' }, icon: FileText },
    { label: 'Mes absences', description: 'Historique et justificatifs', to: { name: 'student-absences' }, icon: AlertTriangle },
    { label: 'Emploi du temps', description: 'Cours de la semaine', to: { name: 'student-timetable' }, icon: CalendarDays },
  ]
})

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    if (isStudent.value) {
      const res = await api<{ data: Student }>('/api/v1/student/me')
      studentProfile.value = res.data
    } else if (isParent.value) {
      const res = await api<{ data: Student[] }>('/api/v1/parent/children')
      children.value = res.data
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Impossible de charger le profil.'
  } finally {
    loading.value = false
  }
}

async function onLogout(): Promise<void> {
  loggingOut.value = true
  try {
    await auth.logout()
    await router.push({ name: 'login' })
  } finally {
    loggingOut.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="portal-dash portal-profile-page portal-mobile">
    <header class="portal-dash-hero portal-profile-hero portal-dash-animate">
      <div class="portal-dash-hero__identity">
        <div class="portal-dash-hero__avatar" aria-hidden="true">{{ initials(displayName) }}</div>
        <div class="portal-dash-hero__text">
          <p class="portal-dash-hero__date">
            <User aria-hidden="true" />
            <span>Mon profil</span>
          </p>
          <h1>{{ displayName }}</h1>
          <p class="portal-dash-hero__meta">
            <span
              class="portal-profile-role-tag"
              :class="{ 'portal-profile-role-tag--parent': isParent }"
            >{{ roleLabel }}</span>
          </p>
          <p v-if="auth.user?.email" class="portal-dash-hero__meta" style="margin-top: 0.35rem">
            <Mail aria-hidden="true" style="width:0.9rem;height:0.9rem" />
            <span>{{ auth.user.email }}</span>
          </p>
        </div>
      </div>
    </header>

    <p v-if="loading" class="portal-child-empty portal-dash-animate">Chargement du profil…</p>
    <p v-else-if="error" class="alert alert-error portal-dash-animate" role="alert">{{ error }}</p>

    <template v-else>
      <!-- Élève : infos scolaires -->
      <section
        v-if="isStudent && studentProfile"
        class="portal-dash-section portal-dash-animate portal-dash-animate--delay-1"
        aria-labelledby="school-info-heading"
      >
        <div class="portal-dash-section__head">
          <h2 id="school-info-heading">Informations scolaires</h2>
        </div>
        <div class="portal-profile-info-grid">
          <div v-if="classroomLabel" class="portal-profile-info-cell">
            <span>Classe</span>
            <strong>{{ classroomLabel }}</strong>
          </div>
          <div v-if="studentProfile.registration_number" class="portal-profile-info-cell">
            <span>Matricule</span>
            <strong>{{ studentProfile.registration_number }}</strong>
          </div>
          <div v-if="!classroomLabel && !studentProfile.registration_number" class="portal-profile-info-cell" style="grid-column: 1 / -1">
            <span>Profil</span>
            <strong>Informations en cours de mise à jour</strong>
          </div>
        </div>
      </section>

      <!-- Parent : accès enfants (lien unique, pas liste dupliquée) -->
      <section
        v-if="isParent"
        class="portal-dash-section portal-dash-animate portal-dash-animate--delay-1"
        aria-labelledby="family-heading"
      >
        <div class="portal-dash-section__head">
          <h2 id="family-heading">Famille</h2>
        </div>
        <RouterLink
          v-if="children.length > 0"
          :to="{ name: 'parent-children' }"
          class="portal-profile-summary-link portal-kpi-link"
        >
          <span class="portal-profile-summary-link__avatar" aria-hidden="true">
            <Users style="width:1.15rem;height:1.15rem" />
          </span>
          <span class="portal-profile-summary-link__copy">
            <strong>Mes enfants</strong>
            <span>{{ childCountLabel }} · Voir le détail</span>
          </span>
          <ChevronRight class="portal-profile-nav-item__chevron" aria-hidden="true" />
        </RouterLink>
        <div v-else class="portal-child-empty">
          <strong>Aucun enfant rattaché</strong>
          <p>Contactez le secrétariat pour associer un enfant à votre compte parent.</p>
        </div>
      </section>

      <!-- Élève : accès rapides (hors barre de navigation) -->
      <section
        v-if="studentLinks.length"
        class="portal-child-section portal-dash-animate portal-dash-animate--delay-2"
        aria-labelledby="access-heading"
      >
        <div class="portal-child-section__head">
          <div>
            <p class="portal-child-section__kicker">
              <GraduationCap aria-hidden="true" />
              Scolarité
            </p>
            <h2 id="access-heading">Accès rapides</h2>
            <p class="portal-child-section__sub">Bulletin, absences et emploi du temps</p>
          </div>
        </div>
        <div class="portal-child-section__body">
          <ul class="portal-profile-nav-list">
            <li v-for="link in studentLinks" :key="link.label">
              <RouterLink :to="link.to" class="portal-profile-nav-item portal-kpi-link">
                <span class="portal-profile-nav-item__icon">
                  <component :is="link.icon" aria-hidden="true" />
                </span>
                <span class="portal-profile-nav-item__copy">
                  <span class="portal-profile-nav-item__label">{{ link.label }}</span>
                  <span class="portal-profile-nav-item__desc">{{ link.description }}</span>
                </span>
                <ChevronRight class="portal-profile-nav-item__chevron" aria-hidden="true" />
              </RouterLink>
            </li>
          </ul>
        </div>
      </section>

      <!-- Compte -->
      <section
        class="portal-child-section portal-dash-animate portal-dash-animate--delay-3"
        aria-labelledby="account-heading"
      >
        <div class="portal-child-section__head">
          <div>
            <p class="portal-child-section__kicker">
              <User aria-hidden="true" />
              Session
            </p>
            <h2 id="account-heading">Compte</h2>
            <p class="portal-child-section__sub">Déconnexion sécurisée de l'application</p>
          </div>
        </div>
        <div class="portal-child-section__body">
          <button
            type="button"
            class="portal-profile-logout"
            :disabled="loggingOut"
            @click="onLogout"
          >
            <LogOut aria-hidden="true" />
            <span>{{ loggingOut ? 'Déconnexion…' : 'Se déconnecter' }}</span>
          </button>
        </div>
      </section>
    </template>
  </section>
</template>
