<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { ApiError } from '../api/client'
import logoUrl from '../assets/logo-educonnect-full.png'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const identifier = ref('')
const password = ref('')
const errorMsg = ref('')
const submitting = ref(false)
const passwordVisible = ref(false)

async function onSubmit(): Promise<void> {
  errorMsg.value = ''
  submitting.value = true
  try {
    await auth.login(identifier.value, password.value)
    const redirect = route.query.redirect as string | undefined
    if (redirect) {
      await router.push(redirect)
    } else if (auth.user?.role === 'parent') {
      await router.push({ name: 'parent-dashboard' })
    } else if (auth.user?.role === 'eleve') {
      await router.push({ name: 'student-dashboard' })
    } else {
      await router.push({ name: 'dashboard' })
    }
  } catch (err) {
    if (err instanceof ApiError) {
      errorMsg.value =
        err.errors?.identifier?.[0] ??
        err.errors?.email?.[0] ??
        err.errors?.password?.[0] ??
        err.message
    } else {
      errorMsg.value = "Erreur réseau, vérifie que l'API tourne sur le port 8000."
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="card">
      <!-- logo -->
      <img :src="logoUrl" alt="EduConnect — Complexe MALUNGA" class="brand-logo" />

      <form @submit.prevent="onSubmit" novalidate>
        <!-- email -->
        <div class="field">
          <label for="identifier">Email</label>
          <div class="input-wrap">
            <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>
            </svg>
            <input
              id="identifier"
              v-model="identifier"
              type="text"
              required
              autofocus
              autocomplete="username"
              placeholder="jane.doe@example.com"
            />
          </div>
        </div>

        <!-- password -->
        <div class="field">
          <label for="password">Mot de passe</label>
          <div class="input-wrap">
            <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            <input
              id="password"
              v-model="password"
              :type="passwordVisible ? 'text' : 'password'"
              required
              autocomplete="current-password"
              placeholder="••••••••"
            />
            <button type="button" class="eye-btn" @click="passwordVisible = !passwordVisible" :aria-label="passwordVisible ? 'Masquer' : 'Afficher'">
              <svg v-if="!passwordVisible" xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg v-else xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </button>
          </div>
          <div class="forgot-row">
            <RouterLink :to="{ name: 'forgot-password' }">Mot de passe oublié ?</RouterLink>
          </div>
        </div>

        <!-- error -->
        <div v-if="errorMsg" class="error-msg" role="alert">{{ errorMsg }}</div>

        <!-- submit -->
        <button type="submit" class="submit-btn" :disabled="submitting">
          {{ submitting ? 'Connexion…' : 'Se connecter' }}
        </button>
      </form>

    </div>
  </div>
</template>

<style scoped>
/* ── Page ── */
.page {
  /* remplit #app (hauteur = --vvh, qui rétrécit avec le clavier iOS) et
     défile à l'intérieur ; margin:auto sur .card recentre sans rogner le haut */
  height: 100%;
  display: flex;
  flex-direction: column;
  background: radial-gradient(ellipse 100% 80% at 50% -10%, #14306e 0%, #0a1838 45%, #060e22 100%);
  padding: 1.5rem;
  overflow-y: auto;
}

/* ── Card ── */
.card {
  width: 100%;
  max-width: 420px;
  /* centre la carte tout en autorisant le scroll si le clavier rétrécit l'espace */
  margin: auto;
  background: linear-gradient(165deg, #15295a 0%, #0e1d44 100%);
  border: 1px solid rgba(96, 165, 250, 0.28);
  border-radius: 20px;
  padding: 1.8rem 2rem 1.6rem;
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.06),
    0 0 40px rgba(37, 99, 235, 0.35),
    0 30px 60px rgba(0, 0, 0, 0.55);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0;
}

/* ── Logo ── */
.brand-logo {
  width: 100%;
  max-width: 340px;
  height: auto;
  object-fit: contain;
  display: block;
  margin: 0 0 1.2rem;
}

/* ── Form ── */
form {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

label {
  color: #c2d2ee;
  font-size: 0.84rem;
  font-weight: 600;
  margin: 0;
}

/* ── Input wrap ── */
.input-wrap {
  position: relative;
  display: flex;
  align-items: center;
}

.input-icon {
  position: absolute;
  left: 0.95rem;
  color: #6f86ad;
  pointer-events: none;
  flex-shrink: 0;
}

.input-wrap input {
  width: 100%;
  height: 52px;
  background: rgba(8, 19, 43, 0.6);
  border: 1px solid rgba(96, 165, 250, 0.2);
  border-radius: 12px;
  padding: 0 2.8rem 0 2.8rem;
  color: #eaf1ff;
  font-size: 0.92rem;
  transition: border-color 0.2s, box-shadow 0.2s;
}

.input-wrap input::placeholder {
  color: #6f86ad;
}

.input-wrap input:focus {
  outline: none;
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.28);
  background: rgba(8, 19, 43, 0.85);
}

.input-wrap input:hover:not(:focus) {
  border-color: rgba(96, 165, 250, 0.4);
}

/* ── Eye toggle ── */
.eye-btn {
  position: absolute;
  right: 0.8rem;
  background: transparent;
  border: none;
  box-shadow: none;
  color: #6f86ad;
  padding: 0.2rem;
  min-height: auto;
  display: flex;
  align-items: center;
  cursor: pointer;
}

.eye-btn:hover:not(:disabled) {
  color: #aebfda;
  background: transparent;
  box-shadow: none;
}

/* ── Forgot ── */
.forgot-row {
  display: flex;
  justify-content: flex-end;
}

.forgot-row a {
  font-size: 0.8rem;
  color: #60a5fa;
  font-weight: 500;
}

.forgot-row a:hover {
  color: #93c5fd;
  text-decoration: underline;
}

/* ── Error ── */
.error-msg {
  background: rgba(220, 38, 38, 0.12);
  border: 1px solid rgba(220, 38, 38, 0.3);
  color: #f87171;
  padding: 0.65rem 0.9rem;
  border-radius: 10px;
  font-size: 0.85rem;
}

/* ── Submit ── */
.submit-btn {
  width: 100%;
  height: 52px;
  background: #2563eb;
  border: none;
  border-radius: 12px;
  color: #fff;
  font-size: 1rem;
  font-weight: 700;
  letter-spacing: 0.01em;
  cursor: pointer;
  box-shadow: 0 4px 20px rgba(37, 99, 235, 0.45);
  transition: background 0.2s, box-shadow 0.2s;
  margin-top: 0.4rem;
}

.submit-btn:hover:not(:disabled) {
  background: #1d4ed8;
  box-shadow: 0 6px 24px rgba(37, 99, 235, 0.55);
}

.submit-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
  box-shadow: none;
}

/* ── Responsive ── */
@media (max-width: 480px) {
  .card {
    padding: 2.2rem 1.4rem 1.8rem;
    border-radius: 16px;
  }

  .brand-logo {
    max-width: 240px;
  }
}
</style>
