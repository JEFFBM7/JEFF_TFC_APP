<script setup lang="ts">
import type { Component } from 'vue'

export interface PodiumItem {
  id: string | number
  title: string
  subtitle: string
  scoreDisplay: string
  tone: 'good' | 'warn' | 'danger'
}

defineProps<{
  title: string
  description: string
  icon: Component
  items: PodiumItem[]
  emptyText: string
  iconClass?: string
  tone?: 'default' | 'watchlist'
}>()
</script>

<template>
  <div class="card podium-card" :class="tone === 'watchlist' ? 'watchlist' : ''">
    <div class="card-header podium-head">
      <div>
        <h2>
          <component :is="icon" class="podium-head-icon" :class="iconClass" />
          {{ title }}
        </h2>
        <p>{{ description }}</p>
      </div>
    </div>
    <ol v-if="items.length > 0" class="podium-list">
      <li v-for="(item, idx) in items" :key="item.id" class="podium-row">
        <span class="podium-rank" :class="`rank-${idx + 1}`">{{ idx + 1 }}</span>
        <div class="podium-info">
          <strong>{{ item.title }}</strong>
          <small>{{ item.subtitle }}</small>
        </div>
        <span class="badge" :class="item.tone === 'good' ? 'badge-success' : 'badge-warn'">
          <span class="badge-dot"></span>
          {{ item.scoreDisplay }}
        </span>
      </li>
    </ol>
    <div v-else class="empty-state-pro">
      <component :is="icon" class="empty-icon" />
      <p>{{ emptyText }}</p>
    </div>
  </div>
</template>

<style scoped>
.podium-card {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.podium-head h2 {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  margin: 0;
  font-size: 1rem;
  line-height: 1.2;
}

.podium-head p {
  margin: 0.35rem 0 0;
  color: var(--text-soft);
  font-size: 0.82rem;
}

.podium-head-icon {
  width: 1.1rem;
  height: 1.1rem;
  flex: 0 0 auto;
}

.podium-head-icon.trophy {
  color: #ca8a04;
}

.podium-head-icon.watch {
  color: #b42318;
}

.podium-list {
  list-style: none;
  margin: 0;
  padding: 0.75rem;
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}

.podium-row {
  display: grid;
  grid-template-columns: 2.4rem minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.65rem;
  padding: 0.55rem 0.65rem;
  border-radius: 10px;
  background: #f8fafc;
  transition: background 0.15s ease;
}

.podium-row:hover {
  background: var(--primary-soft);
}

.podium-rank {
  display: grid;
  place-items: center;
  width: 2rem;
  height: 2rem;
  border-radius: 999px;
  font-size: 0.85rem;
  font-weight: 850;
  color: white;
  background: #94a3b8;
}

.podium-rank.rank-1 {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  box-shadow: 0 2px 8px rgba(245, 158, 11, 0.35);
}

.podium-rank.rank-2 {
  background: linear-gradient(135deg, #94a3b8, #64748b);
}

.podium-rank.rank-3 {
  background: linear-gradient(135deg, #b45309, #92400e);
}

.podium-info {
  min-width: 0;
  display: grid;
  gap: 0.05rem;
}

.podium-info strong {
  overflow: hidden;
  color: var(--text);
  font-size: 0.86rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.podium-info small {
  color: var(--text-soft);
  font-size: 0.74rem;
  font-weight: 600;
}

.badge {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.25rem 0.65rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 750;
  white-space: nowrap;
}

.badge-success {
  background: #ecfdf3;
  color: #027a48;
}

.badge-warn {
  background: #fffaeb;
  color: #b54708;
}

.badge-dot {
  width: 0.4rem;
  height: 0.4rem;
  border-radius: 50%;
}

.badge-success .badge-dot {
  background: #12b76a;
}

.badge-warn .badge-dot {
  background: #f79009;
}

.empty-state-pro {
  min-height: 5.25rem;
  display: grid;
  place-items: center;
  gap: 0.55rem;
  padding: 1.25rem;
  border-top: 1px solid var(--border);
  color: var(--text-soft);
  text-align: center;
}

.empty-icon {
  width: 1.65rem;
  height: 1.65rem;
  color: #cbd5e1;
  stroke-width: 1.75;
}

.empty-state-pro p {
  margin: 0;
  color: var(--text-soft);
  font-size: 0.86rem;
  font-weight: 600;
}

@media (max-width: 640px) {
  .podium-row {
    grid-template-columns: 2.2rem minmax(0, 1fr);
  }

  .badge {
    grid-column: 2;
    justify-self: start;
  }
}
</style>
