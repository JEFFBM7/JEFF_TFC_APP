<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, ApiError } from '../api/client'
import { useAuthStore } from '../stores/auth'

interface HealthResponse {
  ok: boolean
  service: string
  version: string
}

const auth = useAuthStore()
const health = ref<HealthResponse | null>(null)
const error = ref<string>('')

onMounted(async () => {
  try {
    health.value = await api<HealthResponse>('/api/v1/health')
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'API injoignable.'
  }
})
</script>

<template>
  <section>
    <h1>Bienvenue, {{ auth.user?.name }}</h1>
    <p style="color: var(--text-soft)">
      Tu es connecté(e) en tant que <strong>{{ auth.user?.role }}</strong>.
    </p>

    <div class="cards">
      <div class="card">
        <div class="card-body">
          <h2>État de l'API</h2>
          <p v-if="error" class="alert alert-error">{{ error }}</p>
          <p v-else-if="!health" style="color: var(--text-soft)">Chargement…</p>
          <p v-else>
            <span class="badge badge-success">en ligne</span>
            Service <code>{{ health.service }}</code> · version <code>{{ health.version }}</code>
          </p>
        </div>
      </div>

      <div v-if="auth.hasRole('admin')" class="card">
        <div class="card-body">
          <h2>Raccourcis admin</h2>
          <ul style="padding-left: 1.2rem">
            <li>
              <RouterLink :to="{ name: 'school-years' }">Gérer les années scolaires</RouterLink>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 1rem;
  margin-top: 1rem;
}

code {
  font-family: ui-monospace, Consolas, monospace;
  background: #f1f5f9;
  padding: 0.1rem 0.4rem;
  border-radius: 4px;
  font-size: 0.85rem;
}
</style>
