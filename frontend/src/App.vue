<script setup lang="ts">
import { onMounted, watch } from 'vue'
import { setSchoolYearProvider } from './api/client'
import ConfirmDialog from './components/ConfirmDialog.vue'
import { useAuthStore } from './stores/auth'
import { useSchoolYearStore } from './stores/schoolYear'

const auth = useAuthStore()
const schoolYear = useSchoolYearStore()

// Câble le client API au store : injection automatique de school_year_id.
setSchoolYearProvider(() => schoolYear.effectiveId)

onMounted(async () => {
  if (!auth.initialized) {
    await auth.init()
  }
  if (auth.isAuthenticated && !schoolYear.initialized) {
    await schoolYear.init()
  }
})

// Recharge le contexte d'année à chaque login/logout.
watch(
  () => auth.isAuthenticated,
  async (isAuth) => {
    if (isAuth) {
      await schoolYear.init()
    } else {
      schoolYear.reset()
    }
  },
)
</script>

<template>
  <RouterView />
  <ConfirmDialog />
</template>
