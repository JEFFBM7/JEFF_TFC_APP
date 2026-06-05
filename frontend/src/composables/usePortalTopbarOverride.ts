import { inject, provide, ref, type InjectionKey, type Ref } from 'vue'

export interface PortalTopbarOverride {
  title?: string
  subtitle?: string
  avatarText?: string
  onBack?: () => void
  /** Affiche une croix de fermeture en haut à droite (sans titre ni retour). */
  onClose?: () => void
}

interface PortalTopbarOverrideController {
  override: Ref<PortalTopbarOverride | null>
  setOverride: (override: PortalTopbarOverride | null) => void
  clearOverride: () => void
}

const portalTopbarOverrideKey: InjectionKey<PortalTopbarOverrideController> = Symbol('portalTopbarOverride')

export function providePortalTopbarOverride(): PortalTopbarOverrideController {
  const override = ref<PortalTopbarOverride | null>(null)

  const controller: PortalTopbarOverrideController = {
    override,
    setOverride(next) {
      override.value = next
    },
    clearOverride() {
      override.value = null
    },
  }

  provide(portalTopbarOverrideKey, controller)

  return controller
}

export function usePortalTopbarOverride(): PortalTopbarOverrideController | null {
  return inject(portalTopbarOverrideKey, null)
}
