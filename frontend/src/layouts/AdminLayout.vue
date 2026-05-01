<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()

const initials = computed(() => {
  const name = auth.user?.name ?? ''
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p.charAt(0).toUpperCase())
    .join('') || '?'
})

async function onLogout(): Promise<void> {
  await auth.logout()
  await router.push({ name: 'login' })
}
</script>

<template>
  <div class="layout">
    <aside class="sidebar">
      <div class="brand">EduConnect</div>
      <nav>
        <RouterLink :to="{ name: 'dashboard' }" class="nav-link">Tableau de bord</RouterLink>
        <RouterLink
          v-if="auth.hasRole('admin')"
          :to="{ name: 'school-years' }"
          class="nav-link"
        >
          Années scolaires
        </RouterLink>
        <RouterLink
          v-if="auth.hasRole('admin')"
          :to="{ name: 'levels' }"
          class="nav-link"
        >
          Niveaux &amp; Classes
        </RouterLink>
        <RouterLink
          v-if="auth.hasRole('admin')"
          :to="{ name: 'subjects' }"
          class="nav-link"
        >
          Matières
        </RouterLink>
        <RouterLink
          v-if="auth.hasRole('admin')"
          :to="{ name: 'teachers' }"
          class="nav-link"
        >
          Enseignants
        </RouterLink>
      </nav>
    </aside>

    <div class="main">
      <header class="topbar">
        <span class="page-title">{{ $route.meta.title ?? '' }}</span>
        <div class="user-block">
          <div class="user-info">
            <div class="user-name">{{ auth.user?.name }}</div>
            <div class="user-role">{{ auth.user?.role }}</div>
          </div>
          <div class="avatar">{{ initials }}</div>
          <button type="button" @click="onLogout">Déconnexion</button>
        </div>
      </header>

      <main class="content">
        <RouterView />
      </main>
    </div>
  </div>
</template>

<style scoped>
.layout {
  display: grid;
  grid-template-columns: 240px 1fr;
  min-height: 100vh;
}

.sidebar {
  background: #0f172a;
  color: #e2e8f0;
  padding: 1.25rem 1rem;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.brand {
  font-size: 1.15rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  color: white;
}

nav {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.nav-link {
  color: #cbd5e1;
  padding: 0.55rem 0.8rem;
  border-radius: 6px;
  font-size: 0.95rem;
}

.nav-link:hover {
  background: rgba(255, 255, 255, 0.07);
  text-decoration: none;
  color: white;
}

.nav-link.router-link-active {
  background: rgba(37, 99, 235, 0.85);
  color: white;
}

.main {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.topbar {
  background: var(--bg-card);
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.85rem 1.5rem;
}

.page-title {
  font-weight: 600;
  color: var(--text);
}

.user-block {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.user-info {
  text-align: right;
  line-height: 1.15;
}

.user-name {
  font-weight: 600;
  font-size: 0.92rem;
}

.user-role {
  font-size: 0.78rem;
  color: var(--text-soft);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--primary-soft);
  color: var(--primary);
  display: grid;
  place-items: center;
  font-weight: 700;
  font-size: 0.85rem;
}

.content {
  padding: 1.5rem;
  flex: 1 1 0;
  min-width: 0;
}
</style>
