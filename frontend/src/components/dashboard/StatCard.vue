<script setup lang="ts">
import type { Component } from 'vue'
import { TrendingUp, TrendingDown, Minus } from 'lucide-vue-next'

defineProps<{
  label: string
  value: string | number
  note: string
  icon?: Component
  tone?: 'default' | 'warn' | 'danger' | 'good'
  variant?: 'default' | 'editorial'
  delta?: number | null
}>()
</script>

<template>
  <div
    class="stat-card"
    :class="[
      variant === 'editorial' ? 'stat-card--editorial' : 'kpi-card',
      tone === 'default' || !tone ? '' : tone,
    ]"
  >
    <div class="stat-card__header">
      <span class="stat-card__label">{{ label }}</span>
      <div v-if="icon" class="stat-card__icon">
        <component :is="icon" />
      </div>
    </div>
    <span class="stat-card__value" :class="tone && tone !== 'default' ? `value-${tone}` : ''">{{ value }}</span>
    <div class="stat-card__footer">
      <span class="stat-card__note">{{ note }}</span>
      <span
        v-if="delta !== null && delta !== undefined"
        class="stat-card__delta"
        :class="delta > 0 ? 'delta-up' : delta < 0 ? 'delta-down' : 'delta-neutral'"
      >
        <TrendingUp v-if="delta > 0" class="delta-icon" />
        <TrendingDown v-else-if="delta < 0" class="delta-icon" />
        <Minus v-else class="delta-icon" />
        {{ delta > 0 ? '+' : '' }}{{ delta.toFixed(1) }}
      </span>
    </div>
  </div>
</template>

<style scoped>
.stat-card__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 0.65rem;
}

.stat-card__label {
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.stat-card__value {
  display: block;
  color: var(--text);
  font-size: 1.7rem;
  font-weight: 850;
  line-height: 1;
  margin-bottom: 0.45rem;
}

.stat-card__value.value-good { color: var(--success); }
.stat-card__value.value-warn { color: var(--warn); }
.stat-card__value.value-danger { color: var(--danger); }

.stat-card__footer {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.stat-card__note {
  color: var(--text-muted);
  font-size: 0.76rem;
  font-weight: 650;
}

.stat-card__delta {
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
  font-size: 0.72rem;
  font-weight: 700;
  padding: 0.1rem 0.4rem;
  border-radius: 999px;
}

.delta-up { color: var(--success); background: var(--success-soft); }
.delta-down { color: var(--danger); background: var(--danger-soft); }
.delta-neutral { color: var(--text-muted); background: rgba(74, 106, 144, 0.1); }

.delta-icon { width: 0.72rem; height: 0.72rem; }

.stat-card__icon {
  width: 2.2rem;
  height: 2.2rem;
  padding: 0.4rem;
  background: var(--bg-soft, var(--bg-subtle));
  border-radius: 8px;
  color: var(--primary);
  display: grid;
  place-items: center;
}

.stat-card__icon svg {
  width: 100%;
  height: 100%;
  stroke-width: 2;
}

.stat-card.warn .stat-card__icon {
  background: var(--warn-soft);
  color: var(--warn);
}

.stat-card.danger .stat-card__icon {
  background: var(--danger-soft);
  color: var(--danger);
}

.stat-card.good .stat-card__icon {
  background: var(--success-soft);
  color: var(--success);
}

/* ── Variant éditorial ── */
.stat-card--editorial {
  --accent: #c9a227;
  --accent-soft: rgba(201, 162, 39, 0.1);
  position: relative;
  min-height: 9rem;
  padding: 1.25rem 1.3rem 1.1rem;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 0;
  border-radius: 16px;
  border: 1px solid rgba(255, 255, 255, 0.08);
  background: var(--bg-card);
  box-shadow:
    0 1px 0 rgba(255, 255, 255, 0.06) inset,
    0 4px 6px rgba(0, 0, 0, 0.3),
    0 12px 32px rgba(0, 0, 0, 0.4);
  overflow: hidden;
  transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.3s ease;
}

/* Colored top accent bar */
.stat-card--editorial::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 3px;
  background: linear-gradient(90deg, var(--accent), color-mix(in srgb, var(--accent) 60%, transparent));
  border-radius: 16px 16px 0 0;
}

/* Subtle background glow */
.stat-card--editorial::after {
  content: '';
  position: absolute;
  top: -20%;
  right: -10%;
  width: 50%;
  height: 70%;
  background: radial-gradient(circle, var(--accent-soft) 0%, transparent 70%);
  pointer-events: none;
}

.stat-card--editorial:hover {
  box-shadow:
    0 1px 0 rgba(255, 255, 255, 0.06) inset,
    0 8px 16px rgba(0, 0, 0, 0.4),
    0 20px 40px rgba(0, 0, 0, 0.5);
}

.stat-card--editorial.good {
  --accent: #2d6a4f;
  --accent-soft: rgba(45, 106, 79, 0.07);
}

.stat-card--editorial.warn {
  --accent: #c45c26;
  --accent-soft: rgba(196, 92, 38, 0.07);
}

.stat-card--editorial.danger {
  --accent: #9b2c2c;
  --accent-soft: rgba(155, 44, 44, 0.07);
}

.stat-card--editorial .stat-card__label {
  font-size: 0.67rem;
  font-weight: 700;
  letter-spacing: 0.12em;
  color: var(--text-soft);
}

.stat-card--editorial .stat-card__value {
  font-size: 2.4rem;
  font-weight: 900;
  letter-spacing: -0.04em;
  color: var(--text);
  line-height: 1;
  margin-bottom: 0.5rem;
}

.stat-card--editorial .stat-card__value.value-good { color: var(--success); }
.stat-card--editorial .stat-card__value.value-warn { color: var(--warn); }
.stat-card--editorial .stat-card__value.value-danger { color: var(--danger); }

.stat-card--editorial .stat-card__note {
  font-size: 0.76rem;
  color: var(--text-soft);
  font-weight: 500;
}

.stat-card--editorial .stat-card__icon {
  width: 2.4rem;
  height: 2.4rem;
  border-radius: 10px;
  background: var(--accent-soft);
  color: var(--accent);
  border: 1px solid rgba(255, 255, 255, 0.1);
}

.stat-card--editorial.good .stat-card__icon {
  border-color: rgba(45, 106, 79, 0.18);
}

.stat-card--editorial.warn .stat-card__icon {
  border-color: rgba(196, 92, 38, 0.18);
}

.stat-card--editorial.danger .stat-card__icon {
  border-color: rgba(155, 44, 44, 0.18);
}
</style>
