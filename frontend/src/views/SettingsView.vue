<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '../api/client'
import { useToastStore } from '../stores/toast'
import type { AppSettingRow } from '../types'

interface SettingsResponse { data: AppSettingRow[] }

const toast = useToastStore()
const rows = ref<AppSettingRow[]>([])
const loading = ref(false)
const error = ref('')
const saving = ref(false)
const successMessage = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

interface Group {
  title: string
  description: string
  keys: string[]
}

const GROUPS: Group[] = [
  {
    title: 'Absences',
    description: 'Seuils déclenchant une alerte d\'absentéisme (CDC §4.5).',
    keys: ['attendance.consecutive_threshold', 'attendance.rolling_threshold', 'attendance.rolling_window_days'],
  },
  {
    title: 'Retards',
    description: 'Seuil de vigilance sur les retards récurrents.',
    keys: ['attendance.late_threshold', 'attendance.late_window_days'],
  },
  {
    title: 'Notes & moyennes',
    description: 'Vigilance pédagogique à la clôture des trimestres.',
    keys: ['grades.low_average_threshold', 'grades.notify_parents_on_low_average'],
  },
]

const KEY_LABELS: Record<string, string> = {
  'attendance.consecutive_threshold': 'Absences consécutives',
  'attendance.rolling_threshold': 'Absences sur fenêtre glissante',
  'attendance.rolling_window_days': 'Fenêtre d\'absences (jours)',
  'attendance.late_threshold': 'Retards sur fenêtre glissante',
  'attendance.late_window_days': 'Fenêtre des retards (jours)',
  'grades.low_average_threshold': 'Seuil de moyenne faible',
  'grades.notify_parents_on_low_average': 'Notifier les parents à la clôture',
}

const byKey = computed<Record<string, AppSettingRow>>(() => {
  const map: Record<string, AppSettingRow> = {}
  for (const r of rows.value) map[r.key] = r
  return map
})

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const res = await api<SettingsResponse>('/api/v1/admin/settings')
    rows.value = res.data.map((r) => ({ ...r }))
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur de chargement.'
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  saving.value = true
  error.value = ''
  successMessage.value = ''
  fieldErrors.value = {}
  const settings: Record<string, number | boolean | string> = {}
  for (const r of rows.value) settings[r.key] = r.value
  try {
    const res = await api<SettingsResponse>('/api/v1/admin/settings', {
      method: 'PUT',
      body: { settings },
    })
    rows.value = res.data.map((r) => ({ ...r }))
    successMessage.value = 'Paramètres enregistrés.'
    toast.success('Paramètres enregistrés.')
    window.setTimeout(() => (successMessage.value = ''), 3000)
  } catch (e) {
    if (e instanceof ApiError) {
      error.value = e.message
      if (e.errors) fieldErrors.value = e.errors
    } else {
      error.value = 'Erreur réseau.'
    }
  } finally {
    saving.value = false
  }
}

function resetToDefault(row: AppSettingRow): void {
  row.value = row.default
}

function fieldError(key: string): string | null {
  const list = fieldErrors.value[`settings.${key}`]
  return list && list.length > 0 ? list[0] : null
}

onMounted(load)
</script>

<template>
  <section class="page">
    <div v-if="error" class="alert alert-error">{{ error }}</div>
    <div v-if="successMessage" class="alert alert-success">{{ successMessage }}</div>

    <div v-if="loading" class="empty-state">Chargement…</div>

    <template v-else>
      <div v-for="group in GROUPS" :key="group.title" class="card">
        <div class="card-header">
          <div>
            <h2 class="card-title">{{ group.title }}</h2>
            <p class="card-subtitle">{{ group.description }}</p>
          </div>
        </div>

        <div class="settings-grid">
          <div
            v-for="key in group.keys"
            :key="key"
            class="setting-row"
            :class="{ 'has-error': fieldError(key) }"
          >
            <label :for="`s-${key}`" class="setting-label">
              <span class="setting-title">{{ KEY_LABELS[key] ?? key }}</span>
              <span v-if="byKey[key]?.description" class="setting-help">
                {{ byKey[key].description }}
              </span>
            </label>

            <div class="setting-control">
              <template v-if="byKey[key]?.type === 'boolean'">
                <label class="switch">
                  <input
                    :id="`s-${key}`"
                    type="checkbox"
                    :checked="Boolean(byKey[key].value)"
                    @change="(byKey[key].value = ($event.target as HTMLInputElement).checked)"
                  />
                  <span class="switch-slider" aria-hidden="true" />
                </label>
              </template>
              <template v-else>
                <input
                  :id="`s-${key}`"
                  type="number"
                  :step="byKey[key]?.type === 'float' ? '0.1' : '1'"
                  :min="byKey[key]?.min ?? undefined"
                  :max="byKey[key]?.max ?? undefined"
                  :value="byKey[key]?.value"
                  @input="(byKey[key].value = byKey[key]?.type === 'float'
                    ? Number(($event.target as HTMLInputElement).value)
                    : Number(($event.target as HTMLInputElement).value))"
                />
                <span v-if="byKey[key]?.min != null || byKey[key]?.max != null" class="setting-range">
                  ({{ byKey[key].min ?? '−∞' }} – {{ byKey[key].max ?? '+∞' }})
                </span>
              </template>

              <button
                v-if="byKey[key] && byKey[key].value !== byKey[key].default"
                type="button"
                class="reset-button"
                @click="resetToDefault(byKey[key])"
              >
                ↺ Défaut ({{ byKey[key].default }})
              </button>
            </div>
            <small v-if="fieldError(key)" class="err">{{ fieldError(key) }}</small>
          </div>
        </div>
      </div>

      <div class="actions">
        <button type="button" class="btn-primary" :disabled="saving" @click="save">
          {{ saving ? 'Enregistrement…' : 'Enregistrer' }}
        </button>
      </div>
    </template>
  </section>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 1.25rem; }

.card-title { margin: 0; font-size: 1.05rem; font-weight: 800; color: var(--text); }
.card-subtitle { margin: 0.2rem 0 0; color: var(--text-soft); font-size: 0.85rem; }

.settings-grid {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 0.5rem 0;
}

.setting-row {
  display: grid;
  grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
  gap: 1rem;
  align-items: center;
  padding: 0.65rem 0.75rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
}

.setting-row.has-error { border-color: var(--danger); background: var(--danger-soft); }

.setting-label { display: flex; flex-direction: column; gap: 0.2rem; }
.setting-title { font-weight: 700; color: var(--text); font-size: 0.92rem; }
.setting-help { color: var(--text-soft); font-size: 0.78rem; line-height: 1.4; }

.setting-control {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  flex-wrap: wrap;
}

.setting-control input[type="number"] {
  max-width: 8rem;
  font-variant-numeric: tabular-nums;
}

.setting-range { color: var(--text-muted); font-size: 0.78rem; }

.reset-button {
  font-size: 0.76rem;
  font-weight: 700;
  padding: 0.3rem 0.55rem;
  border: 1px solid var(--border);
  background: var(--bg-soft);
  border-radius: 0.5rem;
  cursor: pointer;
}
.reset-button:hover { border-color: var(--primary); color: var(--primary); background: var(--primary-soft); }

.actions { display: flex; justify-content: flex-end; }

.switch {
  position: relative;
  display: inline-block;
  width: 2.6rem;
  height: 1.45rem;
}
.switch input { opacity: 0; width: 0; height: 0; }
.switch-slider {
  position: absolute; inset: 0;
  background: var(--text-muted);
  border-radius: 999px;
  cursor: pointer;
  transition: background 0.2s ease;
}
.switch-slider::before {
  content: ''; position: absolute;
  left: 0.2rem; bottom: 0.2rem;
  width: 1.05rem; height: 1.05rem;
  background: white; border-radius: 50%;
  transition: transform 0.2s ease;
  box-shadow: 0 2px 4px rgba(0,0,0,0.15);
}
.switch input:checked + .switch-slider { background: var(--primary); }
.switch input:checked + .switch-slider::before { transform: translateX(1.15rem); }

.err { display: block; grid-column: 1 / -1; color: var(--danger); font-size: 0.78rem; margin-top: 0.25rem; }

.alert-success { background: var(--success-soft); color: var(--success); border: 1px solid rgba(74, 222, 128, 0.3); }

@media (max-width: 720px) {
  .setting-row { grid-template-columns: 1fr; }
}
</style>
