import { averageOn20ToPercent, formatAveragePercent, formatAveragePercentValue } from '../utils/grades'

export type PerformanceTone = 'good' | 'warn' | 'danger' | 'muted'

export interface PerformanceStatus {
  tone: PerformanceTone
  label: string
  message: string
}

const CHILD_COLORS = ['#2563eb', '#0891b2', '#16a34a', '#d97706', '#7c3aed', '#db2777']

export function usePortalDashboard() {
  function initials(name: string): string {
    const trimmed = name.trim()
    if (!trimmed) return '?'
    return trimmed
      .split(/\s+/)
      .slice(0, 2)
      .map((part) => part.charAt(0).toUpperCase())
      .join('')
  }

  function greeting(): string {
    const hour = new Date().getHours()
    if (hour < 12) return 'Bonjour'
    if (hour < 18) return 'Bon après-midi'
    return 'Bonsoir'
  }

  function todayLabel(): string {
    const raw = new Intl.DateTimeFormat('fr-FR', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    }).format(new Date())
    return raw.charAt(0).toUpperCase() + raw.slice(1)
  }

  function childColor(id: number): string {
    return CHILD_COLORS[id % CHILD_COLORS.length]
  }

  function performanceFromAverage(avg: number | null): PerformanceStatus {
    if (avg === null) {
      return {
        tone: 'muted',
        label: 'En attente',
        message: 'Aucune moyenne enregistrée pour le moment.',
      }
    }
    if (avg >= 16) {
      return { tone: 'good', label: 'Excellence', message: 'Très bonne situation scolaire sur la période.' }
    }
    if (avg >= 14) {
      return { tone: 'good', label: 'Très bien', message: 'Résultats solides sur la période.' }
    }
    if (avg >= 10) {
      return { tone: 'good', label: 'Satisfaisant', message: 'Moyenne validée sur la période.' }
    }
    if (avg >= 8) {
      return { tone: 'warn', label: 'À améliorer', message: 'La moyenne reste sous le seuil de validation.' }
    }
    return { tone: 'danger', label: 'À soutenir', message: 'Un suivi rapproché est recommandé.' }
  }

  function wellbeingLabel(status: string): { tone: PerformanceTone; label: string } {
    switch (status) {
      case 'good':
        return { tone: 'good', label: 'Situation stable' }
      case 'watch':
        return { tone: 'warn', label: 'À surveiller' }
      case 'concern':
        return { tone: 'danger', label: 'Attention requise' }
      default:
        return { tone: 'muted', label: 'Pas de note' }
    }
  }

  function avgPercent(avg: number | null): number {
    const pct = averageOn20ToPercent(avg)
    if (pct === null) return 0
    return Math.max(2, Math.min(100, pct))
  }

  function formatPercentFrom20(value: number | null, digits = 2): string {
    return formatAveragePercentValue(value, digits)
  }

  function formatAverage(value: number | null): string {
    return formatAveragePercent(value, 1)
  }

  function formatShortDate(iso: string | null | undefined): string {
    if (!iso) return '—'
    return new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short' }).format(new Date(iso))
  }

  return {
    initials,
    greeting,
    todayLabel,
    childColor,
    performanceFromAverage,
    wellbeingLabel,
    avgPercent,
    formatPercentFrom20,
    formatAverage,
    formatShortDate,
  }
}
