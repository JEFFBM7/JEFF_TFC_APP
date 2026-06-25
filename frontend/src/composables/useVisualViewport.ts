/**
 * Synchronise la taille réelle visible (clavier compris) vers des variables CSS.
 *
 * Problème iOS Safari : quand le clavier s'ouvre, le navigateur décale le
 * « layout viewport » vers le haut et `100dvh` ne rétrécit jamais. Les éléments
 * `position: fixed` (topbar, sheet de composition) sortent donc de la zone
 * visible. La seule API fiable est `window.visualViewport`, qui expose la zone
 * réellement visible (hauteur + décalage).
 *
 * On expose deux variables sur <html> :
 *   --vvh : hauteur visible en px (rétrécit avec le clavier)
 *   --vvt : décalage du haut de la zone visible (offsetTop du visual viewport)
 *
 * Les conteneurs plein écran utilisent `height: var(--vvh, 100dvh)` et
 * `top: var(--vvt, 0px)` pour rester collés à la zone visible.
 */
export function useVisualViewport(): () => void {
  const vv = typeof window !== 'undefined' ? window.visualViewport : null
  const root = document.documentElement

  let frame = 0

  function apply(): void {
    frame = 0
    if (!vv) return
    root.style.setProperty('--vvh', `${Math.round(vv.height)}px`)
    root.style.setProperty('--vvt', `${Math.round(vv.offsetTop)}px`)
  }

  function schedule(): void {
    if (frame) return
    frame = requestAnimationFrame(apply)
  }

  if (!vv) {
    // Pas de VisualViewport API : on retombe sur les fallbacks CSS (100dvh).
    return () => {}
  }

  apply()
  vv.addEventListener('resize', schedule)
  vv.addEventListener('scroll', schedule)
  window.addEventListener('orientationchange', schedule)

  return () => {
    if (frame) cancelAnimationFrame(frame)
    vv.removeEventListener('resize', schedule)
    vv.removeEventListener('scroll', schedule)
    window.removeEventListener('orientationchange', schedule)
  }
}
