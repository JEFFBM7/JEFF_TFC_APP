<script setup lang="ts">
import type { Component } from 'vue'
import { RouterLink, useRoute, useRouter, type RouteLocationRaw } from 'vue-router'

export interface PortalTab {
  label: string
  to: RouteLocationRaw
  icon?: Component
  badge?: number
  exact?: boolean
}

const props = defineProps<{
  tabs: PortalTab[]
}>()

const route = useRoute()
const router = useRouter()

function tabPath(tab: PortalTab): string {
  return router.resolve(tab.to).path
}

function isActive(tab: PortalTab): boolean {
  const path = tabPath(tab)
  if (tab.exact) return route.path === path
  if (path === '/') return route.path === '/'
  return route.path === path || route.path.startsWith(`${path}/`)
}
</script>

<template>
  <nav class="portal-tab-bar" aria-label="Navigation principale">
    <RouterLink
      v-for="tab in props.tabs"
      :key="tab.label"
      :to="tab.to"
      class="portal-tab"
      :class="{ 'is-active': isActive(tab) }"
      :aria-current="isActive(tab) ? 'page' : undefined"
    >
      <span class="portal-tab-icon-wrap">
        <component v-if="tab.icon" :is="tab.icon" class="portal-tab-icon" aria-hidden="true" />
        <span v-else class="portal-tab-fallback-dot" aria-hidden="true" />
        <span v-if="tab.badge && tab.badge > 0 && !isActive(tab)" class="portal-tab-unread-dot" aria-hidden="true" />
        <span v-if="tab.badge && tab.badge > 0" class="portal-tab-badge">{{ tab.badge > 9 ? '9+' : tab.badge }}</span>
      </span>
      <span class="portal-tab-label" translate="no">{{ tab.label }}</span>
      <span class="portal-tab-indicator" aria-hidden="true" />
    </RouterLink>
  </nav>
</template>

<style scoped>
.portal-tab-bar {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 0;
  z-index: 90;
  display: flex;
  align-items: stretch;
  justify-content: space-around;
  gap: 0.2rem;
  min-height: 4.6rem;
  padding: 0.4rem max(0.65rem, env(safe-area-inset-left)) calc(0.5rem + env(safe-area-inset-bottom)) max(0.65rem, env(safe-area-inset-right));
  border-top: 1px solid var(--border);
  background: rgba(255, 255, 255, 0.97);
  box-shadow: 0 -10px 32px rgba(15, 23, 42, 0.08);
  backdrop-filter: blur(16px);
}

.portal-tab {
  position: relative;
  display: flex;
  min-width: 0;
  flex: 1;
  max-width: 7.5rem;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.2rem;
  padding: 0.35rem 0.5rem 0.25rem;
  border: none;
  border-radius: 12px;
  color: var(--text-soft);
  font-size: 0.68rem;
  font-weight: 850;
  line-height: 1.1;
  text-align: center;
  text-decoration: none;
}

.portal-tab:hover {
  text-decoration: none;
  color: var(--text);
}

.portal-tab.is-active {
  color: var(--primary);
}

.portal-tab-icon-wrap {
  position: relative;
  display: grid;
  width: 1.6rem;
  height: 1.6rem;
  place-items: center;
}

.portal-tab-icon {
  width: 1.3rem;
  height: 1.3rem;
  stroke-width: 2.25;
}

.portal-tab-fallback-dot {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  background: currentColor;
}

.portal-tab-unread-dot {
  position: absolute;
  top: 0;
  right: 0;
  width: 0.45rem;
  height: 0.45rem;
  border: 1.5px solid #fff;
  border-radius: 999px;
  background: var(--danger);
}

.portal-tab-badge {
  position: absolute;
  top: -0.25rem;
  right: -0.45rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 1rem;
  height: 1rem;
  padding: 0 0.25rem;
  border: 1.5px solid #fff;
  border-radius: 999px;
  background: var(--danger);
  color: #fff;
  font-size: 0.58rem;
  font-weight: 900;
}

.portal-tab-label {
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.portal-tab-indicator {
  width: 0.28rem;
  height: 0.28rem;
  border-radius: 999px;
  background: var(--primary);
  opacity: 0;
  transition: opacity 0.18s ease;
}

.portal-tab.is-active .portal-tab-indicator {
  opacity: 1;
}
</style>
