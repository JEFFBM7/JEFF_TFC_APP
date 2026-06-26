import { ref } from 'vue'

export type Theme = 'dark' | 'light'

const THEME_KEY = 'educonnect-theme'
const theme = ref<Theme>('dark')

/** Applique un thème : met à jour <html data-theme>, le ref et localStorage. */
export function applyTheme(next: Theme): void {
  theme.value = next
  document.documentElement.setAttribute('data-theme', next)
  try {
    localStorage.setItem(THEME_KEY, next)
  } catch {
    /* stockage indisponible : on garde au moins l'état en mémoire */
  }
}

/** À appeler une fois au démarrage (avant le mount) pour éviter le flash. */
export function initTheme(): void {
  let saved: Theme = 'dark'
  try {
    const stored = localStorage.getItem(THEME_KEY)
    if (stored === 'light' || stored === 'dark') saved = stored
  } catch {
    /* ignore */
  }
  applyTheme(saved)
}

export function useTheme() {
  return {
    theme,
    toggle: () => applyTheme(theme.value === 'dark' ? 'light' : 'dark'),
    set: applyTheme,
  }
}
