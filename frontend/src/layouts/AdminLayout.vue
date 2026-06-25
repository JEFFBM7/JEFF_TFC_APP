<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import type { Component } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter, type RouteLocationRaw } from 'vue-router'
import { AlertTriangle, CalendarDays, ChevronLeft, FileText, Home, MessageSquare, User, Users, X } from 'lucide-vue-next'
import { api } from '../api/client'
import { MESSAGES_UNREAD_EVENT, subscribeToMessageUpdates, type MessageRealtimeEvent } from '../api/realtime'
import PortalBottomNav, { type PortalTab } from '../components/portal/PortalBottomNav.vue'
import PortalInstallBanner from '../components/portal/PortalInstallBanner.vue'
import { usePortalPwa } from '../composables/usePortalPwa'
import SchoolYearSwitcher from '../components/SchoolYearSwitcher.vue'
import { providePortalTopbarOverride } from '../composables/usePortalTopbarOverride'
import { useAuthStore } from '../stores/auth'
import { useSchoolYearStore } from '../stores/schoolYear'
import type { UserRole } from '../types'

interface NavItem {
  label: string
  to: RouteLocationRaw
  badge?: 'messages'
  icon?: Component
}

interface NavGroup {
  label: string
  items: NavItem[]
}

interface NavContext {
  group: NavGroup
  item: NavItem
  path: string
}

interface BreadcrumbItem {
  label: string
  to?: RouteLocationRaw
}

const auth = useAuthStore()
const schoolYear = useSchoolYearStore()
const router = useRouter()
const route = useRoute()
const unreadCount = ref(0)
const mobileNavOpen = ref(false)
const portalTopbar = providePortalTopbarOverride()

// Le sélecteur d'année ne concerne que les rôles qui manipulent
// des données scolaires globales (pas les parents/élèves qui n'ont qu'une vue contextuelle).
const showSchoolYearSwitcher = computed(() =>
  auth.hasRole('admin', 'enseignant', 'secretariat'),
)

const isPortalUser = computed(() => auth.hasRole('parent', 'eleve'))
const portalPwa = usePortalPwa(computed(() => auth.role))
const showPwaInstallBanner = computed(() => portalPwa.showBanner.value)
const portalInstallIsIos = computed(() => portalPwa.isIos.value)
const portalInstallCanPrompt = computed(() => portalPwa.isInstallable.value)
const portalTopbarOverride = computed(() =>
  isPortalUser.value ? portalTopbar.override.value : null,
)

const showHistoricalBanner = computed(
  () => showSchoolYearSwitcher.value && schoolYear.selected !== null && !schoolYear.isViewingCurrent,
)

const viewContextKey = computed(() =>
  showSchoolYearSwitcher.value ? (schoolYear.effectiveId ?? 'auto') : 'portal',
)

let unreadTimer: number | undefined
const unreadUpdatedEvent = MESSAGES_UNREAD_EVENT
let unsubscribeRealtime: (() => void) | null = null

const roleLabels: Record<UserRole, string> = {
  admin: 'Administrateur',
  enseignant: 'Enseignant',
  parent: 'Parent',
  eleve: 'Élève',
  secretariat: 'Secrétariat',
}

const isGlobalAdmin = computed(() =>
  auth.user?.role === 'admin' && (auth.user.admin_scope ?? 'global') === 'global',
)

const messagesItem: NavItem = {
  label: 'Messagerie',
  to: { name: 'messages' },
  badge: 'messages',
  icon: MessageSquare,
}

const parentChildrenItem: NavItem = {
  label: 'Mes enfants',
  to: { name: 'parent-children' },
  icon: Users,
}

async function refreshUnread(): Promise<void> {
  if (!auth.isAuthenticated) {
    unreadCount.value = 0
    return
  }
  try {
    const res = await api<{ unread: number }>('/api/v1/messages/unread-count')
    unreadCount.value = res.unread
  } catch {
    /* silencieux */
  }
}

function onUnreadUpdated(event: Event): void {
  const next = (event as CustomEvent<number>).detail
  if (typeof next === 'number') {
    unreadCount.value = next
  } else {
    void refreshUnread()
  }
}

function onRealtimeMessage(event: MessageRealtimeEvent): void {
  unreadCount.value = event.unread_count
}

function syncRealtimeSubscription(): void {
  unsubscribeRealtime?.()
  unsubscribeRealtime = null

  if (auth.user?.id) {
    unsubscribeRealtime = subscribeToMessageUpdates(auth.user.id, onRealtimeMessage)
  }
}

onMounted(() => {
  void refreshUnread()
  window.addEventListener(unreadUpdatedEvent, onUnreadUpdated)
  syncRealtimeSubscription()
  unreadTimer = window.setInterval(() => void refreshUnread(), 60_000)
})

onUnmounted(() => {
  window.removeEventListener(unreadUpdatedEvent, onUnreadUpdated)
  unsubscribeRealtime?.()
  if (unreadTimer) window.clearInterval(unreadTimer)
})

watch(
  () => auth.user?.id,
  () => syncRealtimeSubscription(),
)

watch(
  () => route.fullPath,
  () => {
    mobileNavOpen.value = false
  },
)

const initials = computed(() => {
  const name = auth.user?.name ?? ''
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p.charAt(0).toUpperCase())
    .join('') || '?'
})

const roleLabel = computed(() => {
  if (auth.user?.role === 'admin' && auth.user.admin_scope_label) {
    return auth.user.admin_scope_label
  }

  const role = auth.user?.role
  return role ? roleLabels[role] : 'Utilisateur'
})

const pageTitle = computed(() => String(route.meta.title ?? 'EduConnect'))

function itemPath(item: NavItem): string {
  return router.resolve(item.to).path
}

function routeMatches(path: string): boolean {
  if (path === '/') return route.path === '/'
  return route.path === path || route.path.startsWith(`${path}/`)
}

const navigationGroups = computed<NavGroup[]>(() => {
  if (auth.hasRole('parent')) {
    return [
      {
        label: 'Portail parent',
        items: [
          { label: 'Tableau de bord', to: { name: 'parent-dashboard' }, icon: Home },
          parentChildrenItem,
        ],
      },
      {
        label: 'Communication',
        items: [messagesItem],
      },
      {
        label: 'Compte',
        items: [{ label: 'Mon profil', to: { name: 'parent-profile' }, icon: User }],
      },
    ]
  }

  if (auth.hasRole('eleve')) {
    return [
      {
        label: 'Portail élève',
        items: [
          { label: 'Tableau de bord', to: { name: 'student-dashboard' }, icon: Home },
          { label: 'Bulletin', to: { name: 'student-bulletin' }, icon: FileText },
          { label: 'Absences', to: { name: 'student-absences' }, icon: AlertTriangle },
          { label: 'Emploi du temps', to: { name: 'student-timetable' }, icon: CalendarDays },
        ],
      },
      {
        label: 'Communication',
        items: [messagesItem],
      },
      {
        label: 'Compte',
        items: [{ label: 'Mon profil', to: { name: 'student-profile' }, icon: User }],
      },
    ]
  }

  if (auth.hasRole('admin')) {
    const schoolingItems: NavItem[] = [
      ...(isGlobalAdmin.value ? [{ label: 'Années scolaires', to: { name: 'school-years' } }] : []),
      { label: 'Classes', to: { name: 'levels' } },
      { label: 'Cours', to: { name: 'subjects' } },
      { label: 'Emploi du temps', to: { name: 'timetable' } },
    ]
    const groups: NavGroup[] = [
      {
        label: 'Vue générale',
        items: [{ label: 'Tableau de bord', to: { name: 'dashboard' } }],
      },
      {
        label: 'Scolarité',
        items: schoolingItems,
      },
      {
        label: 'Personnes',
        items: [
          { label: 'Élèves', to: { name: 'students' } },
          { label: 'Parents', to: { name: 'parents' } },
          { label: 'Enseignants', to: { name: 'teachers' } },
        ],
      },
      {
        label: 'Pédagogie',
        items: [
          { label: 'Évaluations', to: { name: 'evaluations' } },
          { label: 'Présences', to: { name: 'attendances' } },
          { label: 'Rapports', to: { name: 'reports' } },
          { label: 'Élèves en difficulté', to: { name: 'students-at-risk' } },
        ],
      },
      {
        label: 'Communication',
        items: [messagesItem],
      },
    ]

    if (isGlobalAdmin.value) {
      groups.push({
        label: 'Administration',
        items: [
          { label: 'Utilisateurs', to: { name: 'users' } },
          { label: 'Admins secondaires', to: { name: 'secondary-admins' } },
          { label: 'Paramètres & seuils', to: { name: 'settings' } },
        ],
      })
    }

    return groups
  }

  if (auth.hasRole('enseignant')) {
    return [
      {
        label: 'Vue générale',
        items: [{ label: 'Tableau de bord', to: { name: 'dashboard' } }],
      },
      {
        label: 'Pédagogie',
        items: [
          { label: 'Évaluations', to: { name: 'evaluations' } },
          { label: 'Présences', to: { name: 'attendances' } },
          { label: 'Emploi du temps', to: { name: 'timetable' } },
          { label: 'Rapports', to: { name: 'reports' } },
          { label: 'Élèves en difficulté', to: { name: 'students-at-risk' } },
        ],
      },
      {
        label: 'Communication',
        items: [messagesItem],
      },
    ]
  }

  if (auth.hasRole('secretariat')) {
    return [
      {
        label: 'Vue générale',
        items: [{ label: 'Tableau de bord', to: { name: 'dashboard' } }],
      },
      {
        label: 'Vie scolaire',
        items: [
          { label: 'Présences', to: { name: 'attendances' } },
          { label: 'Emploi du temps', to: { name: 'timetable' } },
        ],
      },
      {
        label: 'Communication',
        items: [messagesItem],
      },
    ]
  }

  return [
    {
      label: 'Navigation',
      items: [{ label: 'Tableau de bord', to: { name: 'dashboard' } }, messagesItem],
    },
  ]
})

const portalHomeTab = computed<NavItem>(() =>
  auth.hasRole('parent')
    ? { label: 'Accueil', to: { name: 'parent-dashboard' }, icon: Home }
    : { label: 'Accueil', to: { name: 'student-dashboard' }, icon: Home },
)

const portalProfileTab = computed<NavItem>(() =>
  auth.hasRole('parent')
    ? { label: 'Profil', to: { name: 'parent-profile' }, icon: User }
    : { label: 'Profil', to: { name: 'student-profile' }, icon: User },
)

const portalBottomTabs = computed<PortalTab[]>(() => {
  const tabs: PortalTab[] = [{
    label: portalHomeTab.value.label,
    to: portalHomeTab.value.to,
    icon: portalHomeTab.value.icon,
    exact: true,
  }]

  if (auth.hasRole('parent')) {
    tabs.push({
      label: parentChildrenItem.label,
      to: parentChildrenItem.to,
      icon: parentChildrenItem.icon,
    })
  }

  if (auth.hasRole('eleve')) {
    tabs.push({
      label: 'Horaire',
      to: { name: 'student-timetable' },
      icon: CalendarDays,
    })
  }

  tabs.push({
    label: 'Messages',
    to: messagesItem.to,
    icon: messagesItem.icon,
    badge: unreadCount.value > 0 ? unreadCount.value : undefined,
  })

  tabs.push({
    label: portalProfileTab.value.label,
    to: portalProfileTab.value.to,
    icon: portalProfileTab.value.icon,
  })

  return tabs
})

const activeNavContext = computed<NavContext | null>(() => {
  let match: (NavContext & { score: number }) | null = null

  for (const group of navigationGroups.value) {
    for (const item of group.items) {
      const path = itemPath(item)
      if (!routeMatches(path)) continue

      const score = path.length
      if (!match || score > match.score) {
        match = { group, item, path, score }
      }
    }
  }

  return match ? { group: match.group, item: match.item, path: match.path } : null
})

const sectionNavItems = computed(() => activeNavContext.value?.group.items ?? [])

const breadcrumbs = computed<BreadcrumbItem[]>(() => {
  const context = activeNavContext.value
  const items: BreadcrumbItem[] = []

  if (context) {
    items.push({ label: context.group.label })
    items.push({ label: context.item.label, to: context.item.to })
  }

  if (!items.some((item) => item.label === pageTitle.value)) {
    items.push({ label: pageTitle.value })
  }

  return items
})

const topbarContext = computed(() => {
  const context = activeNavContext.value
  return context ? `${roleLabel.value} · ${context.group.label}` : roleLabel.value
})

function onPortalTopbarBack(): void {
  portalTopbarOverride.value?.onBack?.()
}

function onPortalTopbarClose(): void {
  portalTopbarOverride.value?.onClose?.()
}

function isNavItemCurrent(item: NavItem): boolean {
  return activeNavContext.value?.path === itemPath(item)
}

function toggleMobileNav(): void {
  mobileNavOpen.value = !mobileNavOpen.value
}

function closeMobileNav(): void {
  mobileNavOpen.value = false
}

async function onLogout(): Promise<void> {
  closeMobileNav()
  await auth.logout()
  await router.push({ name: 'login' })
}
</script>

<template>
  <div
    class="app-shell"
    :class="{
      'is-nav-open': mobileNavOpen && !isPortalUser,
      'is-portal': isPortalUser,
      'has-install-banner': showPwaInstallBanner,
    }"
  >
    <button
      v-if="mobileNavOpen && !isPortalUser"
      type="button"
      class="mobile-overlay"
      aria-label="Fermer la navigation"
      @click="closeMobileNav"
    />

    <aside v-if="!isPortalUser" class="sidebar" aria-label="Navigation principale">
      <div class="sidebar-header">
        <div class="brand-mark">EC</div>
        <div>
          <div class="brand-name">EduConnect</div>
          <div class="brand-subtitle">Complexe MALUNGA</div>
        </div>
      </div>

      <div class="sidebar-profile">
        <div class="avatar">{{ initials }}</div>
        <div class="profile-copy">
          <div class="profile-name">{{ auth.user?.name }}</div>
          <div class="profile-role">{{ roleLabel }}</div>
        </div>
      </div>

      <nav class="nav-groups">
        <section v-for="group in navigationGroups" :key="group.label" class="nav-group">
          <h2 class="nav-group-title" translate="no">{{ group.label }}</h2>
          <RouterLink
            v-for="item in group.items"
            :key="`${group.label}-${item.label}`"
            :to="item.to"
            class="nav-link"
            :class="{ 'is-active': isNavItemCurrent(item) }"
            :aria-current="isNavItemCurrent(item) ? 'page' : undefined"
            @click="closeMobileNav"
          >
            <component v-if="item.icon" :is="item.icon" class="nav-icon" aria-hidden="true" />
            <span v-else class="nav-dot" aria-hidden="true" />
            <span class="nav-label" translate="no">{{ item.label }}</span>
            <span
              v-if="item.badge === 'messages' && unreadCount > 0"
              class="nav-badge"
            >
              {{ unreadCount }}
            </span>
          </RouterLink>
        </section>
      </nav>
    </aside>

    <div class="main">
      <header
        class="topbar"
        :class="{ 'topbar--close-only': Boolean(portalTopbarOverride?.onClose) }"
      >
        <div class="topbar-title-block">
          <button
            v-if="!isPortalUser"
            type="button"
            class="menu-button"
            :aria-expanded="mobileNavOpen"
            aria-label="Ouvrir la navigation"
            @click="toggleMobileNav"
          >
            <span />
            <span />
            <span />
          </button>
          <template v-if="portalTopbarOverride && !portalTopbarOverride.onClose">
            <button
              type="button"
              class="portal-topbar-back"
              aria-label="Retour aux discussions"
              title="Retour aux discussions"
              @click="onPortalTopbarBack"
            >
              <ChevronLeft :size="22" aria-hidden="true" />
            </button>
            <span v-if="portalTopbarOverride.avatarText" class="portal-topbar-avatar" aria-hidden="true">
              {{ portalTopbarOverride.avatarText }}
            </span>
            <div v-if="portalTopbarOverride.title" class="portal-topbar-copy">
              <p v-if="portalTopbarOverride.subtitle" class="topbar-eyebrow">
                {{ portalTopbarOverride.subtitle }}
              </p>
              <h1 class="page-title">{{ portalTopbarOverride.title }}</h1>
            </div>
          </template>
          <div v-else-if="!portalTopbarOverride?.onClose">
            <nav v-if="breadcrumbs.length > 1" class="breadcrumbs" aria-label="Fil d'Ariane">
              <template v-for="(crumb, index) in breadcrumbs" :key="`${crumb.label}-${index}`">
                <RouterLink v-if="crumb.to && index < breadcrumbs.length - 1" :to="crumb.to">
                  {{ crumb.label }}
                </RouterLink>
                <span v-else>{{ crumb.label }}</span>
                <span v-if="index < breadcrumbs.length - 1" class="breadcrumb-separator">/</span>
              </template>
            </nav>
            <p v-else class="topbar-eyebrow">{{ topbarContext }}</p>
            <h1 class="page-title">{{ pageTitle }}</h1>
          </div>
        </div>

        <div class="topbar-actions">
          <button
            v-if="portalTopbarOverride?.onClose"
            type="button"
            class="portal-topbar-close"
            aria-label="Fermer le communiqué"
            title="Fermer"
            @click="onPortalTopbarClose"
          >
            <X :size="22" aria-hidden="true" />
          </button>
          <SchoolYearSwitcher v-if="showSchoolYearSwitcher" />
          <RouterLink v-if="!isPortalUser" :to="{ name: 'messages' }" class="message-link">
            Messagerie
            <span v-if="unreadCount > 0" class="topbar-badge">{{ unreadCount }}</span>
          </RouterLink>
          <div v-if="!isPortalUser" class="topbar-user">
            <div class="user-copy">
              <span class="user-name">{{ auth.user?.name }}</span>
              <span class="user-email">{{ auth.user?.email }}</span>
            </div>
            <div class="avatar compact">{{ initials }}</div>
          </div>
          <button v-if="!isPortalUser" type="button" class="logout-button" @click="onLogout">Déconnexion</button>
        </div>
      </header>

      <div v-if="showHistoricalBanner" class="historical-banner" role="status">
        <span class="historical-icon" aria-hidden="true">!</span>
        <div class="historical-text">
          <strong>Vous consultez l'année {{ schoolYear.selected?.name }}</strong>
          <span v-if="schoolYear.isViewingArchived"> (archivée — lecture seule)</span>
          <span v-else> — différente de l'année courante</span>
        </div>
        <button type="button" class="historical-cta" @click="schoolYear.resetToCurrent()">
          Revenir à l'année courante
        </button>
      </div>


      <nav
        v-if="sectionNavItems.length > 1"
        class="section-nav-shell"
        aria-label="Navigation de section"
      >
        <div class="section-nav">
          <RouterLink
            v-for="item in sectionNavItems"
            :key="`section-${item.label}`"
            :to="item.to"
            class="section-nav-link"
            :class="{ 'is-active': isNavItemCurrent(item) }"
            :aria-current="isNavItemCurrent(item) ? 'page' : undefined"
          >
            <component v-if="item.icon" :is="item.icon" class="section-nav-icon" aria-hidden="true" />
            <span translate="no">{{ item.label }}</span>
            <span
              v-if="item.badge === 'messages' && unreadCount > 0"
              class="section-nav-badge"
            >
              {{ unreadCount }}
            </span>
          </RouterLink>
        </div>
      </nav>

      <main
        class="content scrollbar-hidden"
        :class="{ 'content--messages': route.name === 'messages' }"
      >
        <RouterView v-slot="{ Component, route: viewRoute }">
          <Transition name="view-fade" mode="out-in">
            <!-- path + contexte année : pas fullPath (sinon un router.replace sur la query remonte la vue, ex. messagerie). -->
            <component :is="Component" :key="`${viewRoute.path}:${viewContextKey}`" />
          </Transition>
        </RouterView>
      </main>
    </div>

    <PortalInstallBanner
      v-if="showPwaInstallBanner"
      :is-ios="portalInstallIsIos"
      :can-prompt="portalInstallCanPrompt"
      @install="portalPwa.promptInstall"
      @snooze="portalPwa.snoozeInstall"
      @dismiss="portalPwa.dismissInstall"
    />

    <PortalBottomNav v-if="isPortalUser" :tabs="portalBottomTabs" />
  </div>
</template>

<style scoped>
.app-shell {
  --sidebar-width: 280px;
  display: grid;
  grid-template-columns: var(--sidebar-width) minmax(0, 1fr);
  height: 100%;
  background:
    radial-gradient(circle at top left, rgba(59, 130, 246, 0.06), transparent 28rem),
    var(--bg);
}

.app-shell.is-portal {
  grid-template-columns: minmax(0, 1fr);
}

.app-shell.is-portal .content {
  padding-bottom: calc(5.5rem + env(safe-area-inset-bottom));
}

.app-shell.is-portal.has-install-banner .content {
  padding-bottom: calc(10.5rem + env(safe-area-inset-bottom));
}

.sidebar {
  position: sticky;
  top: 0;
  height: 100%;
  overflow-y: auto;
  background: var(--bg-card);
  border-right: 1px solid var(--border);
  padding: 1rem;
  z-index: 20;
}

.sidebar-header {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  padding: 0.25rem 0.25rem 1rem;
}

.brand-mark {
  width: 2.35rem;
  height: 2.35rem;
  border-radius: var(--radius);
  display: grid;
  place-items: center;
  background: linear-gradient(135deg, #1d4ed8, #0f2455);
  color: #60a5fa;
  font-weight: 800;
  letter-spacing: 0.02em;
  box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
}

.brand-name {
  color: var(--text);
  font-size: 1.05rem;
  font-weight: 800;
  line-height: 1.1;
}

.brand-subtitle {
  margin-top: 0.15rem;
  color: var(--text-soft);
  font-size: 0.76rem;
  font-weight: 600;
}

.sidebar-profile {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 1rem;
  padding: 0.75rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-subtle);
}

.profile-copy {
  min-width: 0;
}

.profile-name {
  overflow: hidden;
  color: var(--text);
  font-size: 0.88rem;
  font-weight: 700;
  line-height: 1.2;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.profile-role {
  margin-top: 0.15rem;
  color: var(--text-soft);
  font-size: 0.74rem;
  font-weight: 700;
  text-transform: uppercase;
}

.nav-groups {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.nav-group {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.nav-group-title {
  margin: 0.35rem 0.55rem 0.2rem;
  color: var(--text-muted);
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.nav-link {
  position: relative;
  display: flex;
  align-items: center;
  gap: 0.65rem;
  min-height: 2.45rem;
  padding: 0.56rem 0.65rem;
  border-radius: var(--radius);
  color: var(--text-soft);
  font-size: 0.91rem;
  font-weight: 600;
  text-decoration: none;
  transition: all 0.2s ease;
}

.nav-link:hover {
  background: var(--bg-subtle);
  color: var(--text);
  text-decoration: none;
  transform: translateX(3px);
}

.nav-link.is-active {
  background: linear-gradient(135deg, var(--primary-soft), rgba(59, 130, 246, 0.08));
  color: var(--accent);
  font-weight: 700;
  border-left: 2px solid var(--primary);
  padding-left: calc(0.65rem - 2px);
}

.nav-dot {
  width: 0.45rem;
  height: 0.45rem;
  flex: 0 0 auto;
  border-radius: 999px;
  background: var(--border-strong);
}

.nav-link.is-active .nav-dot {
  background: var(--primary);
  box-shadow: 0 0 0 3px var(--primary-tint);
}

.nav-icon {
  width: 1rem;
  height: 1rem;
  flex: 0 0 auto;
  color: var(--text-muted);
  stroke-width: 2.2;
}

.nav-link:hover .nav-icon,
.nav-link.is-active .nav-icon {
  color: var(--primary);
}

.nav-label {
  min-width: 0;
  flex: 1;
}

.nav-badge,
.topbar-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 1.2rem;
  height: 1.2rem;
  padding: 0 0.35rem;
  border-radius: 999px;
  background: var(--danger);
  color: white;
  font-size: 0.68rem;
  font-weight: 800;
}

.main {
  min-width: 0;
  display: flex;
  flex-direction: column;
  height: 100%;
  max-height: 100%;
  overflow: hidden;
}

.topbar {
  position: sticky;
  top: 0;
  z-index: 80;
  min-height: 4.35rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.85rem 1.5rem;
  border-bottom: 1px solid var(--border);
  background: rgba(5, 13, 31, 0.9);
  backdrop-filter: blur(18px);
}

.topbar-title-block,
.topbar-actions,
.topbar-user {
  display: flex;
  align-items: center;
}

.topbar-title-block {
  min-width: 0;
  gap: 0.85rem;
}

.topbar-title-block > div {
  min-width: 0;
}

.portal-topbar-back {
  display: grid;
  width: 2.5rem;
  height: 2.5rem;
  flex: 0 0 auto;
  place-items: center;
  padding: 0;
  border: 0;
  border-radius: 50%;
  background: var(--bg-subtle);
  color: var(--text);
  cursor: pointer;
}

.portal-topbar-back:hover {
  background: var(--border);
}

.topbar--close-only {
  min-height: 3rem;
  padding-block: 0.45rem;
  justify-content: flex-end;
}

.topbar--close-only .topbar-title-block {
  flex: 1;
  min-height: 0;
}

.topbar--close-only .topbar-actions {
  margin-left: auto;
}

.portal-topbar-close {
  display: grid;
  width: 2.75rem;
  height: 2.75rem;
  flex: 0 0 auto;
  place-items: center;
  padding: 0;
  border: 0;
  border-radius: 50%;
  background: var(--bg-subtle);
  color: var(--text-soft);
  cursor: pointer;
  touch-action: manipulation;
  transition: background 0.15s ease, color 0.15s ease, transform 0.12s ease;
}

.portal-topbar-close:hover {
  background: var(--border);
  color: var(--text);
}

.portal-topbar-close:active {
  transform: scale(0.96);
}

.portal-topbar-close:focus-visible {
  outline: 2px solid var(--primary);
  outline-offset: 2px;
}

.portal-topbar-avatar {
  display: grid;
  width: 2.25rem;
  height: 2.25rem;
  flex: 0 0 auto;
  place-items: center;
  border-radius: 50%;
  background: var(--primary);
  color: #fff;
  font-size: 0.8rem;
  font-weight: 900;
}

.portal-topbar-copy {
  min-width: 0;
}

.portal-topbar-copy .page-title {
  overflow: hidden;
  max-width: min(58vw, 28rem);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.topbar-eyebrow {
  margin: 0 0 0.12rem;
  color: var(--text-soft);
  font-size: 0.74rem;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.breadcrumbs {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  margin-bottom: 0.12rem;
  color: var(--text-soft);
  font-size: 0.74rem;
  font-weight: 750;
  min-width: 0;
}

.breadcrumbs a,
.breadcrumbs span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.breadcrumbs a {
  color: var(--text-soft);
  text-decoration: none;
}

.breadcrumbs a:hover {
  color: var(--primary);
  text-decoration: none;
}

.breadcrumb-separator {
  color: var(--text-muted);
  flex: 0 0 auto;
}

.page-title {
  margin: 0;
  color: var(--text);
  font-size: 1.28rem;
  font-weight: 800;
  line-height: 1.15;
}

.topbar-actions {
  gap: 0.65rem;
}

.message-link,
.logout-button {
  min-height: 2.15rem;
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  border-radius: var(--radius);
  font-size: 0.84rem;
  font-weight: 800;
}

.message-link {
  padding: 0.45rem 0.7rem;
  border: 1px solid var(--border);
  background: var(--bg-card);
  color: var(--text);
  text-decoration: none;
}

.message-link:hover {
  border-color: var(--border-strong);
  background: var(--bg-subtle);
  text-decoration: none;
}

.topbar-user {
  gap: 0.55rem;
  padding: 0.25rem 0.55rem 0.25rem 0.65rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
}

.user-copy {
  display: flex;
  min-width: 0;
  flex-direction: column;
  line-height: 1.15;
  text-align: right;
}

.user-name {
  max-width: 9rem;
  overflow: hidden;
  color: var(--text);
  font-size: 0.82rem;
  font-weight: 800;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.user-email {
  max-width: 10rem;
  overflow: hidden;
  color: var(--text-soft);
  font-size: 0.7rem;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.logout-button {
  padding: 0.45rem 0.75rem;
}

.avatar {
  width: 2.1rem;
  height: 2.1rem;
  flex: 0 0 auto;
  border-radius: 50%;
  background: linear-gradient(135deg, #1d4ed8, #0f2455);
  color: #60a5fa;
  display: grid;
  place-items: center;
  font-size: 0.78rem;
  font-weight: 900;
}

.avatar.compact {
  width: 1.95rem;
  height: 1.95rem;
}

.menu-button {
  display: none;
  width: 2.35rem;
  height: 2.35rem;
  padding: 0;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  gap: 0.24rem;
}

.menu-button span {
  width: 1rem;
  height: 2px;
  border-radius: 999px;
  background: var(--text);
}

.content {
  width: 100%;
  max-width: 1480px;
  margin: 0 auto;
  padding: 1.35rem;
  flex: 1 1 auto;
  min-width: 0;
  min-height: 0;
  overflow-y: auto;
}

.content.content--messages {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  padding-block: 0.85rem;
}

.historical-banner {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  padding: 0.55rem 1.5rem;
  border-bottom: 1px solid rgba(251, 191, 36, 0.3);
  background: linear-gradient(90deg, rgba(251, 191, 36, 0.1), rgba(251, 191, 36, 0.05));
  color: var(--warn);
  font-size: 0.85rem;
  font-weight: 700;
}

.historical-icon {
  font-size: 1rem;
}

.historical-text {
  flex: 1;
  min-width: 0;
}

.historical-text strong {
  font-weight: 800;
}

.historical-cta {
  padding: 0.35rem 0.7rem;
  border: 1px solid rgba(251, 191, 36, 0.4);
  border-radius: 999px;
  background: rgba(251, 191, 36, 0.1);
  color: var(--warn);
  font-size: 0.78rem;
  font-weight: 800;
  cursor: pointer;
  transition: background 0.15s ease, border-color 0.15s ease;
}

.historical-cta:hover {
  background: rgba(251, 191, 36, 0.18);
  border-color: rgba(251, 191, 36, 0.6);
}

.section-nav-shell {
  position: sticky;
  top: 4.35rem;
  z-index: 9;
  border-bottom: 1px solid var(--border);
  background: rgba(5, 13, 31, 0.9);
  backdrop-filter: blur(14px);
}

.section-nav {
  display: flex;
  gap: 0.35rem;
  max-width: 1480px;
  margin: 0 auto;
  overflow-x: auto;
  padding: 0.55rem 1.35rem;
}

.section-nav-link {
  align-items: center;
  border: 1px solid transparent;
  border-radius: var(--radius);
  color: var(--text-soft);
  display: inline-flex;
  flex: 0 0 auto;
  font-size: 0.84rem;
  font-weight: 800;
  gap: 0.4rem;
  min-height: 2rem;
  padding: 0.38rem 0.68rem;
  text-decoration: none;
}

.section-nav-icon {
  width: 0.95rem;
  height: 0.95rem;
  flex: 0 0 auto;
}

.section-nav-link:hover {
  background: var(--primary-soft);
  border-color: var(--border-strong);
  color: var(--accent);
  text-decoration: none;
}

.section-nav-link.is-active {
  background: var(--bg-card);
  border-color: var(--border-strong);
  color: var(--primary);
  box-shadow: var(--shadow);
}

.section-nav-badge {
  align-items: center;
  background: var(--danger);
  border-radius: 999px;
  color: white;
  display: inline-flex;
  font-size: 0.68rem;
  font-weight: 900;
  height: 1.1rem;
  justify-content: center;
  min-width: 1.1rem;
  padding: 0 0.3rem;
}

.view-fade-enter-active,
.view-fade-leave-active {
  transition:
    opacity 0.14s ease,
    transform 0.14s ease;
}

.view-fade-enter-from {
  opacity: 0;
  transform: translateY(0.35rem);
}

.view-fade-leave-to {
  opacity: 0;
  transform: translateY(-0.2rem);
}

.mobile-overlay {
  display: none;
}

.portal-bottom-nav {
  display: none;
}

@media (max-width: 1180px) {
  .topbar-user .user-copy {
    display: none;
  }
}

@media (max-width: 920px) {
  .app-shell {
    display: block;
  }

  .sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    width: min(86vw, var(--sidebar-width));
    height: 100dvh;
    transform: translateX(-105%);
    transition: transform 0.2s ease;
  }

  .is-nav-open .sidebar {
    transform: translateX(0);
  }

  .mobile-overlay {
    position: fixed;
    inset: 0;
    z-index: 15;
    display: block;
    border: 0;
    border-radius: 0;
    background: rgba(15, 23, 42, 0.42);
    padding: 0;
  }

  .menu-button {
    display: inline-flex;
  }

  .topbar {
    min-height: 4rem;
    padding: 0.8rem 1rem;
  }

  .section-nav-shell {
    top: 4rem;
  }

  .section-nav {
    padding: 0.5rem 1rem;
  }

  .message-link {
    display: none;
  }

  .content {
    padding: 1rem;
  }

  .app-shell.is-portal .section-nav-shell {
    display: none;
  }
}

@media (max-width: 620px) {
  .topbar-actions {
    gap: 0.45rem;
  }

  .topbar-user {
    display: none;
  }

  .page-title {
    font-size: 1.05rem;
  }

  .breadcrumbs {
    display: none;
  }

  .topbar-eyebrow {
    font-size: 0.66rem;
  }

  .logout-button {
    padding: 0.4rem 0.6rem;
    font-size: 0.78rem;
  }
}
</style>
