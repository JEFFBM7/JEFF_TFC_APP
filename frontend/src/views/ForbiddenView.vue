<script setup lang="ts">
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
</script>

<template>
  <div class="wrapper">
    <div class="card">
      <div class="card-body" style="text-align: center">
        <h1>Accès refusé</h1>
        <p style="color: var(--text-soft)">
          Vous n'avez pas les permissions nécessaires pour accéder à cette page.
        </p>
        <p>
          <RouterLink v-if="auth.isAuthenticated" :to="{ name: 'dashboard' }">
            Retour au tableau de bord
          </RouterLink>
          <RouterLink v-else :to="{ name: 'login' }">Se connecter</RouterLink>
        </p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wrapper {
  min-height: 80vh;
  display: grid;
  place-items: center;
}
</style>
