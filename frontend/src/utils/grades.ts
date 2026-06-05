/** Moyenne normalisée sur /20 → pourcentage (0–100). */
export function averageOn20ToPercent(value: number | null | undefined): number | null {
  if (value === null || value === undefined || Number.isNaN(value)) return null
  return (value / 20) * 100
}

/** Affiche une moyenne /20 en pourcentage, ex. « 75,0 % ». */
export function formatAveragePercent(
  value: number | null | undefined,
  digits = 1,
): string {
  const pct = averageOn20ToPercent(value)
  if (pct === null) return '—'
  return `${pct.toFixed(digits).replace('.', ',')} %`
}

/** Valeur numérique seule, ex. « 75,00 » (sans symbole %). */
export function formatAveragePercentValue(
  value: number | null | undefined,
  digits = 2,
): string {
  const pct = averageOn20ToPercent(value)
  if (pct === null) return '—'
  return pct.toFixed(digits).replace('.', ',')
}

/** Séries graphiques : convertit les moyennes /20 en %. */
export function chartPercentFromAverage20(value: number | null | undefined): number | null {
  const pct = averageOn20ToPercent(value)
  if (pct === null) return null
  return Number(pct.toFixed(2))
}
