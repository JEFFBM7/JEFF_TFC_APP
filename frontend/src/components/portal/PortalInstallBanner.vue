<script setup lang="ts">
import { Download, Share, X } from 'lucide-vue-next'

defineProps<{
  isIos: boolean
  canPrompt: boolean
}>()

const emit = defineEmits<{
  install: []
  snooze: []
  dismiss: []
}>()
</script>

<template>
  <aside class="portal-install-banner" role="region" aria-label="Installation de l'application">
    <div class="portal-install-banner-inner">
      <div class="portal-install-banner-icon" aria-hidden="true">
        <Download v-if="canPrompt" :size="20" />
        <Share v-else :size="20" />
      </div>

      <div class="portal-install-banner-copy">
        <p class="portal-install-banner-title">
          {{ canPrompt ? 'Installer l\'application' : 'Ajouter à l\'écran d\'accueil' }}
        </p>
        <p v-if="canPrompt" class="portal-install-banner-text">
          Accédez rapidement à EduConnect sans ouvrir le navigateur.
        </p>
        <p v-else-if="isIos" class="portal-install-banner-text">
          Appuyez sur <strong>Partager</strong>, puis <strong>Sur l'écran d'accueil</strong>.
        </p>
      </div>

      <div class="portal-install-banner-actions">
        <button
          v-if="canPrompt"
          type="button"
          class="portal-install-banner-btn portal-install-banner-btn--primary"
          @click="emit('install')"
        >
          Installer
        </button>
        <button
          type="button"
          class="portal-install-banner-btn"
          @click="emit('snooze')"
        >
          Plus tard
        </button>
        <button
          type="button"
          class="portal-install-banner-close"
          aria-label="Ne plus afficher"
          @click="emit('dismiss')"
        >
          <X :size="18" aria-hidden="true" />
        </button>
      </div>
    </div>
  </aside>
</template>
