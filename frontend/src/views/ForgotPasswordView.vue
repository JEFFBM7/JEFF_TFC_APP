<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api, ApiError } from '../api/client'

const email = ref('')
const submitting = ref(false)
const success = ref('')
const error = ref('')

async function onSubmit(): Promise<void> {
  submitting.value = true
  error.value = ''
  success.value = ''
  try {
    const res = await api<{ message: string }>('/api/v1/auth/forgot-password', {
      method: 'POST',
      body: { email: email.value },
    })
    success.value = res.message
  } catch (e) {
    error.value = e instanceof ApiError ? (e.errors?.email?.[0] ?? e.message) : 'Erreur réseau.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="card login-card">
      <div class="card-header"><h1 style="margin: 0">Mot de passe oublié</h1></div>
      <form class="card-body" @submit.prevent="onSubmit">
        <p class="hint">Saisissez votre adresse e-mail pour recevoir un lien de réinitialisation.</p>
        <div class="field">
          <label for="email">Email</label>
          <input id="email" v-model="email" type="email" required autofocus />
        </div>
        <div v-if="error" class="alert alert-error">{{ error }}</div>
        <div v-if="success" class="alert" style="background: var(--success-soft); color: var(--success)">{{ success }}</div>
        <button type="submit" class="btn-primary" :disabled="submitting" style="width: 100%">
          {{ submitting ? 'Envoi…' : 'Envoyer le lien' }}
        </button>
        <p style="text-align: center; margin-top: 0.75rem">
          <RouterLink :to="{ name: 'login' }">← Retour à la connexion</RouterLink>
        </p>
      </form>
    </div>
  </div>
</template>

<style scoped>
.page { min-height: 100vh; display: grid; place-items: center; padding: 1rem; background: var(--bg); }
.login-card { width: 100%; max-width: 24rem; }
.card-body { padding: 1.2rem; display: flex; flex-direction: column; gap: 0.75rem; }
.hint { color: var(--text-soft); font-size: 0.88rem; margin: 0; }
.field { display: flex; flex-direction: column; gap: 0.35rem; }
.field label { font-size: 0.88rem; color: var(--text-soft); }
</style>
