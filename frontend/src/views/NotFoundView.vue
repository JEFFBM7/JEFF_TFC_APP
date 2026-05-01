<template>
  <div class="wrapper">
    <div class="card">
      <div class="card-body" style="text-align: center">
        <h1>Page introuvable</h1>
        <p style="color: var(--text-soft)">
          La page demandée n'existe pas ou a été déplacée.
        </p>
        <p><RouterLink :to="{ name: 'dashboard' }">Retour à l'accueil</RouterLink></p>
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
