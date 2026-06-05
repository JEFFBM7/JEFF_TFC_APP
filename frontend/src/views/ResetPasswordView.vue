<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '../api/client'

const route = useRoute()
const router = useRouter()

const token = ref((route.query.token as string) ?? '')
const email = ref((route.query.email as string) ?? '')
const password = ref('')
const passwordConfirmation = ref('')
const submitting = ref(false)
const error = ref('')
const success = ref('')

async function onSubmit(): Promise<void> {
  submitting.value = true
  error.value = ''
  success.value = ''
  try {
    const res = await api<{ message: string }>('/api/v1/auth/reset-password', {
      method: 'POST',
      body: {
        token: token.value,
        email: email.value,
        password: password.value,
        password_confirmation: passwordConfirmation.value,
      },
    })
    success.value = res.message
    setTimeout(() => void router.push({ name: 'login' }), 2000)
  } catch (e) {
    error.value = e instanceof ApiError ? (e.errors?.email?.[0] ?? e.errors?.password?.[0] ?? e.message) : 'Erreur réseau.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="card login-card">
      <div class="card-header"><h1 style="margin: 0">Réinitialiser le mot de passe</h1></div>
      <form class="card-body" @submit.prevent="onSubmit">
        <div class="field">
          <label for="email">Email</label>
          <input id="email" v-model="email" type="email" required />
        </div>
        <div class="field">
          <label for="password">Nouveau mot de passe</label>
          <input id="password" v-model="password" type="password" required minlength="8" />
        </div>
        <div class="field">
          <label for="password_c">Confirmer le mot de passe</label>
          <input id="password_c" v-model="passwordConfirmation" type="password" required minlength="8" />
        </div>
        <input type="hidden" :value="token" />
        <div v-if="error" class="alert alert-error">{{ error }}</div>
        <div v-if="success" class="alert" style="background: var(--success-soft); color: var(--success)">{{ success }}</div>
        <button type="submit" class="btn-primary" :disabled="submitting" style="width: 100%">
          {{ submitting ? 'Réinitialisation…' : 'Réinitialiser' }}
        </button>
      </form>
    </div>
  </div>
</template>

<style scoped>
.page { min-height: 100vh; display: grid; place-items: center; padding: 1rem; background: var(--bg); }
.login-card { width: 100%; max-width: 24rem; }
.card-body { padding: 1.2rem; display: flex; flex-direction: column; gap: 0.75rem; }
.field { display: flex; flex-direction: column; gap: 0.35rem; }
.field label { font-size: 0.88rem; color: var(--text-soft); }
</style>
