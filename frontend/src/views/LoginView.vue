<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { ApiError } from '../api/client'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref('admin@educonnect.test')
const password = ref('password')
const errorMsg = ref('')
const submitting = ref(false)

async function onSubmit(): Promise<void> {
  errorMsg.value = ''
  submitting.value = true
  try {
    await auth.login(email.value, password.value)
    const redirect = route.query.redirect as string | undefined
    if (redirect) {
      await router.push(redirect)
    } else {
      await router.push(
        auth.user?.role === 'parent' ? { name: 'parent-dashboard' } : { name: 'dashboard' },
      )
    }
  } catch (err) {
    if (err instanceof ApiError) {
      errorMsg.value =
        err.errors?.email?.[0] ??
        err.errors?.password?.[0] ??
        err.message
    } else {
      errorMsg.value = 'Erreur réseau, vérifie que l\'API tourne sur le port 8000.'
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="card login-card">
      <div class="card-header">
        <h1 style="margin: 0">EduConnect</h1>
      </div>
      <form class="card-body" @submit.prevent="onSubmit">
        <p class="hint">Connecte-toi avec un compte de test.</p>

        <div class="field">
          <label for="email">Email</label>
          <input id="email" v-model="email" type="email" required autofocus />
        </div>

        <div class="field">
          <label for="password">Mot de passe</label>
          <input id="password" v-model="password" type="password" required />
        </div>

        <div v-if="errorMsg" class="alert alert-error">{{ errorMsg }}</div>

        <button type="submit" class="btn-primary" :disabled="submitting" style="width: 100%">
          {{ submitting ? 'Connexion…' : 'Se connecter' }}
        </button>

        <p style="text-align: center; margin-top: 0.5rem">
          <RouterLink :to="{ name: 'forgot-password' }" style="font-size: 0.88rem">Mot de passe oublié ?</RouterLink>
        </p>

        <p class="seed-hint">
          Compte seed : <code>admin@educonnect.test</code> / <code>password</code>
        </p>
      </form>
    </div>
  </div>
</template>

<style scoped>
.page {
  min-height: 100vh;
  display: grid;
  place-items: center;
  padding: 1.5rem;
}
.login-card {
  width: 100%;
  max-width: 22rem;
}
.hint {
  font-size: 0.9rem;
  color: var(--text-soft);
  margin-bottom: 1rem;
}
.seed-hint {
  margin-top: 1rem;
  font-size: 0.8rem;
  color: var(--text-soft);
}
code {
  font-family: ui-monospace, Consolas, monospace;
  background: #f1f5f9;
  border-radius: 4px;
  padding: 0.1rem 0.35rem;
  font-size: 0.78rem;
}
</style>
