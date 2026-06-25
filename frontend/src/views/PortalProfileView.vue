<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import {
  AlertTriangle,
  CalendarDays,
  ChevronRight,
  FileText,
  LogOut,
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
    <header class="portal-profile-title portal-dash-animate">
      <h1>Mon profil</h1>
    </header>

    <!-- Carte d'identité -->
    <div class="portal-profile-id portal-dash-animate">
      <div class="portal-profile-id__avatar" aria-hidden="true">{{ initials(displayName) }}</div>
      <div class="portal-profile-id__info">
        <strong>{{ displayName }}</strong>
        <span v-if="auth.user?.email" class="portal-profile-id__email">{{ auth.user.email }}</span>
        <span
          class="portal-profile-role-tag"
          :class="{ 'portal-profile-role-tag--parent': isParent }"
        >{{ roleLabel }}</span>
      </div>
    </div>

    <p v-if="loading" class="portal-child-empty portal-dash-animate">Chargement du profil…</p>
    <p v-else-if="error" class="alert alert-error portal-dash-animate" role="alert">{{ error }}</p>

    <template v-else>
      <!-- Élève : infos scolaires -->
      <section
        v-if="isStudent && studentProfile"
        class="portal-settings-group portal-dash-animate portal-dash-animate--delay-1"
        aria-labelledby="school-info-heading"
      >
        <p id="school-info-heading" class="portal-settings-group__label">Informations scolaires</p>
        <div class="portal-settings-card">
          <div v-if="classroomLabel" class="portal-settings-row portal-settings-row--static">
            <span class="portal-settings-row__icon"><GraduationCap aria-hidden="true" /></span>
            <span class="portal-settings-row__label">Classe</span>
            <span class="portal-settings-row__value">{{ classroomLabel }}</span>
          </div>
          <div v-if="studentProfile.registration_number" class="portal-settings-row portal-settings-row--static">
            <span class="portal-settings-row__icon"><User aria-hidden="true" /></span>
            <span class="portal-settings-row__label">Matricule</span>
            <span class="portal-settings-row__value">{{ studentProfile.registration_number }}</span>
          </div>
          <div v-if="!classroomLabel && !studentProfile.registration_number" class="portal-settings-row portal-settings-row--static">
            <span class="portal-settings-row__icon"><User aria-hidden="true" /></span>
            <span class="portal-settings-row__label">Profil</span>
            <span class="portal-settings-row__value">Mise à jour en cours</span>
          </div>
        </div>
      </section>

      <!-- Élève : accès rapides (hors barre de navigation) -->
      <section
        v-if="studentLinks.length"
        class="portal-settings-group portal-dash-animate portal-dash-animate--delay-2"
        aria-labelledby="access-heading"
      >
        <p id="access-heading" class="portal-settings-group__label">Scolarité</p>
        <div class="portal-settings-card">
          <RouterLink
            v-for="link in studentLinks"
            :key="link.label"
            :to="link.to"
            class="portal-settings-row portal-kpi-link"
          >
            <span class="portal-settings-row__icon">
              <component :is="link.icon" aria-hidden="true" />
            </span>
            <span class="portal-settings-row__label">{{ link.label }}</span>
            <ChevronRight class="portal-settings-row__chevron" aria-hidden="true" />
          </RouterLink>
        </div>
      </section>

      <!-- Parent : accès enfants -->
      <section
        v-if="isParent"
        class="portal-settings-group portal-dash-animate portal-dash-animate--delay-1"
        aria-labelledby="family-heading"
      >
        <p id="family-heading" class="portal-settings-group__label">Famille</p>
        <div class="portal-settings-card">
          <RouterLink
            v-if="children.length > 0"
            :to="{ name: 'parent-children' }"
            class="portal-settings-row portal-kpi-link"
          >
            <span class="portal-settings-row__icon"><Users aria-hidden="true" /></span>
            <span class="portal-settings-row__label">Mes enfants</span>
            <span class="portal-settings-row__value">{{ childCountLabel }}</span>
            <ChevronRight class="portal-settings-row__chevron" aria-hidden="true" />
          </RouterLink>
          <div v-else class="portal-child-empty">
            <strong>Aucun enfant rattaché</strong>
            <p>Contactez le secrétariat pour associer un enfant à votre compte parent.</p>
          </div>
        </div>
      </section>

      <!-- Compte -->
      <section
        class="portal-settings-group portal-dash-animate portal-dash-animate--delay-3"
        aria-labelledby="account-heading"
      >
        <p id="account-heading" class="portal-settings-group__label">Compte</p>
        <div class="portal-settings-card">
          <button
            type="button"
            class="portal-settings-row portal-settings-row--danger"
            :disabled="loggingOut"
            @click="onLogout"
          >
            <span class="portal-settings-row__icon portal-settings-row__icon--danger">
              <LogOut aria-hidden="true" />
            </span>
            <span class="portal-settings-row__label">{{ loggingOut ? 'Déconnexion…' : 'Se déconnecter' }}</span>
            <ChevronRight class="portal-settings-row__chevron" aria-hidden="true" />
          </button>
        </div>
      </section>
    </template>
  </section>
</template>
