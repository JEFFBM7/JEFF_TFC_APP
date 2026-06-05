<script setup lang="ts">
import type { Component } from 'vue'

defineProps<{
  label: string
  value: string | number
  note: string
  icon?: Component
  tone?: 'default' | 'warn' | 'danger' | 'good'
  variant?: 'default' | 'editorial'
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
    <span class="stat-card__note">{{ note }}</span>
  </div>
</template>

<style scoped>
.stat-card__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 0.5rem;
}

.stat-card__label {
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.stat-card__value {
  color: var(--text);
  font-size: 1.7rem;
  font-weight: 850;
  line-height: 1;
}

.stat-card__value.value-good { color: var(--success); }
.stat-card__value.value-warn { color: var(--warn); }
.stat-card__value.value-danger { color: var(--danger); }

.stat-card__note {
  color: var(--text-muted);
  font-size: 0.76rem;
  font-weight: 650;
}

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
  background: #fff8e6;
  color: #f79009;
}

.stat-card.danger .stat-card__icon {
  background: #fef3f2;
  color: #f04438;
}

.stat-card.good .stat-card__icon {
  background: #ecfdf3;
  color: #16a34a;
}

/* ── Variant éditorial (dashboard admin) ── */
.stat-card--editorial {
  --accent: #c9a227;
  position: relative;
  min-height: 8.5rem;
  padding: 1.15rem 1.2rem 1rem 1.35rem;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 0.4rem;
  border-radius: 14px;
  border: 1px solid rgba(26, 39, 68, 0.08);
  border-left: 3px solid var(--accent);
  background:
    linear-gradient(145deg, rgba(255, 255, 255, 0.97) 0%, rgba(250, 247, 242, 0.92) 100%);
  box-shadow:
    0 1px 0 rgba(255, 255, 255, 0.8) inset,
    0 12px 32px rgba(26, 39, 68, 0.07);
  overflow: hidden;
  transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.35s ease;
}

.stat-card--editorial::after {
  content: '';
  position: absolute;
  top: -40%;
  right: -20%;
  width: 55%;
  height: 80%;
  background: radial-gradient(circle, rgba(201, 162, 39, 0.07) 0%, transparent 70%);
  pointer-events: none;
}

.stat-card--editorial:hover {
  transform: translateY(-3px);
  box-shadow:
    0 1px 0 rgba(255, 255, 255, 0.8) inset,
    0 20px 40px rgba(26, 39, 68, 0.11);
}

.stat-card--editorial.good { --accent: #2d6a4f; }
.stat-card--editorial.warn { --accent: #c45c26; }
.stat-card--editorial.danger { --accent: #9b2c2c; }

.stat-card--editorial .stat-card__label {
  font-size: 0.68rem;
  font-weight: 600;
  letter-spacing: 0.12em;
  color: #5c6478;
}

.stat-card--editorial .stat-card__value {
  font-size: 2.35rem;
  font-weight: 850;
  letter-spacing: -0.03em;
  color: #1a2744;
  line-height: 0.95;
}

.stat-card--editorial .stat-card__value.value-good { color: #2d6a4f; }
.stat-card--editorial .stat-card__value.value-warn { color: #c45c26; }
.stat-card--editorial .stat-card__value.value-danger { color: #9b2c2c; }

.stat-card--editorial .stat-card__note {
  font-size: 0.78rem;
  color: #6b7289;
  font-weight: 500;
}

.stat-card--editorial .stat-card__icon {
  width: 2.35rem;
  height: 2.35rem;
  border-radius: 10px;
  background: rgba(201, 162, 39, 0.12);
  color: #8a6f1a;
  border: 1px solid rgba(201, 162, 39, 0.18);
}

.stat-card--editorial.good .stat-card__icon {
  background: rgba(45, 106, 79, 0.1);
  color: #2d6a4f;
  border-color: rgba(45, 106, 79, 0.15);
}

.stat-card--editorial.warn .stat-card__icon {
  background: rgba(196, 92, 38, 0.1);
  color: #c45c26;
  border-color: rgba(196, 92, 38, 0.15);
}

.stat-card--editorial.danger .stat-card__icon {
  background: rgba(155, 44, 44, 0.1);
  color: #9b2c2c;
  border-color: rgba(155, 44, 44, 0.15);
}
</style>
