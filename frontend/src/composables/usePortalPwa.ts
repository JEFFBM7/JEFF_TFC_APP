import { computed, onMounted, onUnmounted, ref, watch, type Ref } from 'vue'
import type { UserRole } from '../types'
import {
  isInstallBannerDismissed,
  isInstallBannerSnoozed,
  isIosSafari,
  isPwaRole,
  isStandaloneDisplayMode,
  pwaDismissKey,
  pwaSnoozeKey,
  updateManifestLink,
} from '../utils/portalPwa'

interface BeforeInstallPromptEvent extends Event {
  prompt(): Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

function readBannerState(role: UserRole | null) {
  if (!isPwaRole(role)) {
    return { dismissed: false, snoozed: false }
  }
  return {
    dismissed: isInstallBannerDismissed(role),
    snoozed: isInstallBannerSnoozed(role),
  }
}

export function usePortalPwa(role: Ref<UserRole | null>) {
  const isStandalone = ref(isStandaloneDisplayMode())
  const isInstallable = ref(false)
  const isDismissed = ref(false)
  const isSnoozed = ref(false)
  const deferredPrompt = ref<BeforeInstallPromptEvent | null>(null)

  const isIos = computed(() => isIosSafari())

  const showBanner = computed(() => {
    if (!isPwaRole(role.value)) return false
    if (isStandalone.value) return false
    if (isDismissed.value || isSnoozed.value) return false
    return isInstallable.value || isIos.value
  })

  function syncBannerState(nextRole: UserRole | null): void {
    const state = readBannerState(nextRole)
    isDismissed.value = state.dismissed
    isSnoozed.value = state.snoozed
  }

  function onBeforeInstallPrompt(event: Event): void {
    event.preventDefault()
    deferredPrompt.value = event as BeforeInstallPromptEvent
    isInstallable.value = true
  }

  function onDisplayModeChange(): void {
    isStandalone.value = isStandaloneDisplayMode()
  }

  async function promptInstall(): Promise<void> {
    const prompt = deferredPrompt.value
    if (!prompt) return
    await prompt.prompt()
    await prompt.userChoice
    deferredPrompt.value = null
    isInstallable.value = false
  }

  function snoozeInstall(): void {
    const currentRole = role.value
    if (!isPwaRole(currentRole)) return
    isSnoozed.value = true
    sessionStorage.setItem(pwaSnoozeKey(currentRole), '1')
  }

  function dismissInstall(): void {
    const currentRole = role.value
    if (!isPwaRole(currentRole)) return
    isDismissed.value = true
    localStorage.setItem(pwaDismissKey(currentRole), '1')
  }

  watch(
    role,
    (nextRole) => {
      updateManifestLink(isPwaRole(nextRole) ? nextRole : null)
      syncBannerState(nextRole)
    },
    { immediate: true },
  )

  onMounted(() => {
    syncBannerState(role.value)
    window.addEventListener('beforeinstallprompt', onBeforeInstallPrompt)
    window.matchMedia?.('(display-mode: standalone)')?.addEventListener('change', onDisplayModeChange)
  })

  onUnmounted(() => {
    window.removeEventListener('beforeinstallprompt', onBeforeInstallPrompt)
    window.matchMedia?.('(display-mode: standalone)')?.removeEventListener('change', onDisplayModeChange)
  })

  return {
    isStandalone,
    isInstallable,
    isIos,
    showBanner,
    promptInstall,
    snoozeInstall,
    dismissInstall,
  }
}
