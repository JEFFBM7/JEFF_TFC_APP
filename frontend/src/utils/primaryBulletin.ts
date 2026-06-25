import {
  getPrimaryBulletinRows,
  PRIMARY_GRAND_TOTAL_MAX,
  type PrimaryBulletinTier,
  type PrimarySubjectRow,
} from '../data/primaryBulletinStructure'
import {
  getPrimaryMoyenBulletinRows,
  PRIMARY_MOYEN_GRAND_TOTAL_MAX,
} from '../data/primaryMoyenBulletinStructure'
import {
  getPrimaryTerminalBulletinRows,
  PRIMARY_TERMINAL_GRAND_TOTAL_MAX,
} from '../data/primaryTerminalBulletinStructure'
import type { ReportCardData, ReportCardEvaluation, ReportCardSubject } from '../types'

export interface PrimaryTrimesterScores {
  max: number | null
  period1: number | null
  period2: number | null
  examMax: number | null
  exam: number | null
  total: number | null
}

export interface PrimaryFilledRow extends PrimarySubjectRow {
  t1: PrimaryTrimesterScores
  t2: PrimaryTrimesterScores
  t3: PrimaryTrimesterScores
  grandTotal: number | null
}

export interface PrimaryBulletinTotals {
  /** Somme des maxima « MAX per » (1re colonne du 1er trimestre). */
  maxPerPeriod: number
  maximaT1: PrimaryTrimesterScores
  maximaT2: PrimaryTrimesterScores
  maximaT3: PrimaryTrimesterScores
  totalsT1: PrimaryTrimesterScores
  totalsT2: PrimaryTrimesterScores
  totalsT3: PrimaryTrimesterScores
  grandTotal: number | null
  percentage: number | null
  maxGrandTotal: number
}

type TrimesterKey = 't1' | 't2' | 't3'

function normalizeLabel(value: string): string {
  return value
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
}

function findSubject(
  subjects: ReportCardSubject[],
  row: PrimarySubjectRow,
): ReportCardSubject | undefined {
  if (row.kind !== 'subject') return undefined
  const targets = [row.label, ...(row.aliases ?? [])].map(normalizeLabel)

  return subjects.find((subject) => {
    const name = normalizeLabel(subject.subject_name)
    return targets.some((target) => name.includes(target) || target.includes(name))
  })
}

function scalePoints(averageOn20: number | null | undefined, maxPoints: number): number | null {
  if (averageOn20 === null || averageOn20 === undefined || maxPoints <= 0) return null
  return Math.round((averageOn20 / 20) * maxPoints * 100) / 100
}

function periodScore(
  evaluations: ReportCardEvaluation[] | undefined,
  periodId: number | undefined,
  periodMax: number,
): number | null {
  if (!evaluations?.length || !periodId) return null

  const rows = evaluations.filter(
    (evaluation) =>
      evaluation.component === 'continuous'
      && evaluation.period_id === periodId
      && evaluation.normalized_value !== null,
  )

  if (rows.length === 0) return null

  const avg = rows.reduce((sum, row) => sum + (row.normalized_value ?? 0), 0) / rows.length
  return scalePoints(avg, periodMax)
}

function examScore(
  evaluations: ReportCardEvaluation[] | undefined,
  examMax: number,
): number | null {
  if (!evaluations?.length || examMax <= 0) return null

  const rows = evaluations.filter(
    (evaluation) =>
      evaluation.component === 'exam'
      && evaluation.normalized_value !== null,
  )

  if (rows.length === 0) return null

  const avg = rows.reduce((sum, row) => sum + (row.normalized_value ?? 0), 0) / rows.length
  return scalePoints(avg, examMax)
}

function buildTrimesterScores(
  subject: ReportCardSubject | undefined,
  row: PrimarySubjectRow,
  periodIds: [number | undefined, number | undefined],
): PrimaryTrimesterScores {
  if (row.kind !== 'subject' || !subject) {
    const hasMaxima = row.kind === 'subject' || row.kind === 'subtotal'

    return {
      max: hasMaxima ? row.trimesterMax : null,
      period1: null,
      period2: null,
      examMax: hasMaxima ? row.examMax : null,
      exam: null,
      total: null,
    }
  }

  const p1 = periodScore(subject.evaluations, periodIds[0], row.periodMax)
  const p2 = periodScore(subject.evaluations, periodIds[1], row.periodMax)
  const exam = examScore(subject.evaluations, row.examMax)

  let total: number | null = null
  if (p1 !== null || p2 !== null || exam !== null) {
    total = Math.round(((p1 ?? 0) + (p2 ?? 0) + (exam ?? 0)) * 100) / 100
  } else if (subject.average !== null) {
    total = scalePoints(subject.average, row.trimesterMax)
  }

  return {
    max: row.trimesterMax,
    period1: p1,
    period2: p2,
    examMax: row.examMax,
    exam,
    total,
  }
}

function sumTrimester(rows: PrimaryFilledRow[], trimester: TrimesterKey): PrimaryTrimesterScores {
  const subjects = rows.filter((row) => row.kind === 'subject')
  const sum = (pick: (row: PrimaryFilledRow) => number | null): number | null => {
    const values = subjects.map(pick).filter((value): value is number => value !== null)
    return values.length ? Math.round(values.reduce((a, b) => a + b, 0) * 100) / 100 : null
  }

  return {
    max: sum((row) => row[trimester].max),
    period1: sum((row) => row[trimester].period1),
    period2: sum((row) => row[trimester].period2),
    examMax: sum((row) => row[trimester].examMax),
    exam: sum((row) => row[trimester].exam),
    total: sum((row) => row[trimester].total),
  }
}

function periodIdsFromReport(report: ReportCardData | null): [number | undefined, number | undefined] {
  return [
    report?.period_averages?.[0]?.period_id,
    report?.period_averages?.[1]?.period_id,
  ]
}

function resolveGrandTotalMax(tier: PrimaryBulletinTier): number {
  if (tier === 'terminal') return PRIMARY_TERMINAL_GRAND_TOTAL_MAX
  return tier === 'moyen' ? PRIMARY_MOYEN_GRAND_TOTAL_MAX : PRIMARY_GRAND_TOTAL_MAX
}

function resolveBulletinRows(tier: PrimaryBulletinTier): PrimarySubjectRow[] {
  if (tier === 'terminal') return getPrimaryTerminalBulletinRows()
  return tier === 'moyen' ? getPrimaryMoyenBulletinRows() : getPrimaryBulletinRows()
}

export function buildPrimaryBulletinRows(
  trimesterReports: [ReportCardData | null, ReportCardData | null, ReportCardData | null],
  tier: PrimaryBulletinTier = 'debut',
): { rows: PrimaryFilledRow[]; totals: PrimaryBulletinTotals } {
  const bulletinRows = resolveBulletinRows(tier)
  const maxGrandTotal = resolveGrandTotalMax(tier)
  const periodIds = [
    periodIdsFromReport(trimesterReports[0]),
    periodIdsFromReport(trimesterReports[1]),
    periodIdsFromReport(trimesterReports[2]),
  ] as const

  const rows: PrimaryFilledRow[] = bulletinRows.map((row) => {
    const subjectT1 = findSubject(trimesterReports[0]?.subjects ?? [], row)
    const subjectT2 = findSubject(trimesterReports[1]?.subjects ?? [], row)
    const subjectT3 = findSubject(trimesterReports[2]?.subjects ?? [], row)
    const t1 = buildTrimesterScores(subjectT1, row, periodIds[0])
    const t2 = buildTrimesterScores(subjectT2, row, periodIds[1])
    const t3 = buildTrimesterScores(subjectT3, row, periodIds[2])
    const grandTotal = [t1.total, t2.total, t3.total].every((value) => value === null)
      ? null
      : Math.round(((t1.total ?? 0) + (t2.total ?? 0) + (t3.total ?? 0)) * 100) / 100

    return { ...row, t1, t2, t3, grandTotal }
  })

  const totalsT1 = sumTrimester(rows, 't1')
  const totalsT2 = sumTrimester(rows, 't2')
  const totalsT3 = sumTrimester(rows, 't3')

  const grandTotal = totalsT1.total !== null || totalsT2.total !== null || totalsT3.total !== null
    ? Math.round(((totalsT1.total ?? 0) + (totalsT2.total ?? 0) + (totalsT3.total ?? 0)) * 100) / 100
    : null

  const percentage = grandTotal !== null && maxGrandTotal > 0
    ? Math.round((grandTotal / maxGrandTotal) * 10000) / 100
    : null

  const periodMaxSum = rows.filter((r) => r.kind === 'subject').reduce((s, r) => s + r.periodMax, 0)

  const buildMaxima = (totals: PrimaryTrimesterScores): PrimaryTrimesterScores => ({
    max: totals.max,
    period1: periodMaxSum,
    period2: periodMaxSum,
    examMax: totals.examMax,
    exam: null,
    total: totals.max,
  })

  return {
    rows,
    totals: {
      maxPerPeriod: periodMaxSum,
      maximaT1: buildMaxima(totalsT1),
      maximaT2: buildMaxima(totalsT2),
      maximaT3: buildMaxima(totalsT3),
      totalsT1,
      totalsT2,
      totalsT3,
      grandTotal,
      percentage,
      maxGrandTotal,
    },
  }
}

export function primaryAnnualMax(row: PrimarySubjectRow): number | null {
  if (row.kind !== 'subject' && row.kind !== 'subtotal') return null
  return row.trimesterMax * 3
}

export { formatBulletinPercent, formatBulletinPoints } from './ctebBulletin'
