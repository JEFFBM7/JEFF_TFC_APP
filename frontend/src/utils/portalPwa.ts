import type { UserRole } from '../types'

export const PORTAL_PWA_DISMISS_KEY = 'portal-pwa-install-dismissed'
export const PORTAL_PWA_SNOOZE_KEY = 'portal-pwa-install-snoozed'

/** Rôles famille (portail mobile parent / élève). */
export type PortalRole = Extract<UserRole, 'parent' | 'eleve'>

/** Tous les rôles pouvant installer l'application PWA. */
export type PwaRole = UserRole

export function isPortalRole(role: UserRole | null | undefined): role is PortalRole {
  return role === 'parent' || role === 'eleve'
}

export function isPwaRole(role: UserRole | null | undefined): role is PwaRole {
  return role === 'admin'
    || role === 'enseignant'
    || role === 'secretariat'
    || role === 'parent'
    || role === 'eleve'
}

export function isStandaloneDisplayMode(): boolean {
  if (typeof window === 'undefined') return false
  try {
    return (
      window.matchMedia?.('(display-mode: standalone)')?.matches === true
      || (window.navigator as Navigator & { standalone?: boolean }).standalone === true
    )
  } catch {
    return false
  }
}

export function isIosSafari(): boolean {
  if (typeof navigator === 'undefined') return false
  const ua = navigator.userAgent
  const isIos =
    /iPad|iPhone|iPod/.test(ua) ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
  const isSafari = /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS|Chrome/.test(ua)
  return isIos && isSafari
}

export function getPortalDeviceName(): string {
  if (!isStandaloneDisplayMode()) return 'spa-web'
  return isIosSafari() ? 'portal-pwa-ios' : 'portal-pwa'
}

export function manifestHrefForRole(role: PwaRole): string {
  switch (role) {
    case 'parent':
      return '/pwa/manifest-parent.webmanifest'
    case 'eleve':
      return '/pwa/manifest-student.webmanifest'
    case 'enseignant':
      return '/pwa/manifest-teacher.webmanifest'
    case 'secretariat':
      return '/pwa/manifest-secretariat.webmanifest'
    case 'admin':
    default:
      return '/pwa/manifest-admin.webmanifest'
  }
}

export function updateManifestLink(role: PwaRole | null): void {
  const link = document.getElementById('pwa-manifest') as HTMLLinkElement | null
  if (!link || !role) return
  const href = manifestHrefForRole(role)
  if (link.getAttribute('href') === href) return
  link.setAttribute('href', href)
}

export function pwaDismissKey(role: PwaRole): string {
  return `${PORTAL_PWA_DISMISS_KEY}:${role}`
}

export function pwaSnoozeKey(role: PwaRole): string {
  return `${PORTAL_PWA_SNOOZE_KEY}:${role}`
}

export function isInstallBannerDismissed(role: PwaRole): boolean {
  if (typeof localStorage === 'undefined') return false
  if (localStorage.getItem(pwaDismissKey(role)) === '1') return true
  if (isPortalRole(role) && localStorage.getItem(PORTAL_PWA_DISMISS_KEY) === '1') {
    return true
  }
  return false
}

export function isInstallBannerSnoozed(role: PwaRole): boolean {
  if (typeof sessionStorage === 'undefined') return false
  if (sessionStorage.getItem(pwaSnoozeKey(role)) === '1') return true
  if (isPortalRole(role) && sessionStorage.getItem(PORTAL_PWA_SNOOZE_KEY) === '1') {
    return true
  }
  return false
}
