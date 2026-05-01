<script setup lang="ts">
import { onMounted } from 'vue'
import { useAuthStore } from './stores/auth'

const auth = useAuthStore()

onMounted(async () => {
  if (!auth.initialized) {
    await auth.init()
  }
})
</script>

<template>
  <RouterView />
</template>
