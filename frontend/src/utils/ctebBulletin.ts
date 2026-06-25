import {
  getCtebBulletinRows,
  type CtebGradeYear,
  type CtebSubjectRow,
} from '../data/ctebBulletinStructure'
import type { ReportCardData, ReportCardEvaluation, ReportCardSubject } from '../types'

export interface CtebSemesterScores {
  max: number | null
  period1: number | null
  period2: number | null
  examMax: number | null
  exam: number | null
  total: number | null
}

export interface CtebFilledRow extends CtebSubjectRow {
  s1: CtebSemesterScores
  s2: CtebSemesterScores
  grandTotal: number | null
  retakePercent: number | null
}

export interface CtebBulletinTotals {
  maximaS1: CtebSemesterScores
  maximaS2: CtebSemesterScores
  totalsS1: CtebSemesterScores
  totalsS2: CtebSemesterScores
  grandTotal: number | null
  percentage: number | null
  maxGrandTotal: number
}

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
  row: CtebSubjectRow,
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

function buildSemesterScores(
  subject: ReportCardSubject | undefined,
  row: CtebSubjectRow,
  periodIds: [number | undefined, number | undefined],
): CtebSemesterScores {
  if (row.kind !== 'subject' || !subject) {
    const hasMaxima = row.kind === 'subject' || row.kind === 'subtotal'

    return {
      max: hasMaxima ? row.semesterMax : null,
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
    total = scalePoints(subject.average, row.semesterMax)
  }

  return {
    max: row.semesterMax,
    period1: p1,
    period2: p2,
    examMax: row.examMax,
    exam,
    total,
  }
}

function sumSemester(rows: CtebFilledRow[], semester: 's1' | 's2'): CtebSemesterScores {
  const subjects = rows.filter((row) => row.kind === 'subject')
  const sum = (pick: (row: CtebFilledRow) => number | null): number | null => {
    const values = subjects.map(pick).filter((value): value is number => value !== null)
    return values.length ? Math.round(values.reduce((a, b) => a + b, 0) * 100) / 100 : null
  }

  return {
    max: sum((row) => row[semester].max),
    period1: sum((row) => row[semester].period1),
    period2: sum((row) => row[semester].period2),
    examMax: sum((row) => row[semester].examMax),
    exam: sum((row) => row[semester].exam),
    total: sum((row) => row[semester].total),
  }
}

export function buildCtebBulletinRows(
  semesterReports: [ReportCardData | null, ReportCardData | null],
  gradeYear: CtebGradeYear = 7,
): { rows: CtebFilledRow[]; totals: CtebBulletinTotals } {
  const bulletinRows = getCtebBulletinRows(gradeYear)
  const periodIdsS1: [number | undefined, number | undefined] = [
    semesterReports[0]?.period_averages?.[0]?.period_id,
    semesterReports[0]?.period_averages?.[1]?.period_id,
  ]
  const periodIdsS2: [number | undefined, number | undefined] = [
    semesterReports[1]?.period_averages?.[0]?.period_id,
    semesterReports[1]?.period_averages?.[1]?.period_id,
  ]

  const rows: CtebFilledRow[] = bulletinRows.map((row) => {
    const subjectS1 = findSubject(semesterReports[0]?.subjects ?? [], row)
    const subjectS2 = findSubject(semesterReports[1]?.subjects ?? [], row)
    const s1 = buildSemesterScores(subjectS1, row, periodIdsS1)
    const s2 = buildSemesterScores(subjectS2, row, periodIdsS2)
    const grandTotal = [s1.total, s2.total].every((value) => value === null)
      ? null
      : Math.round(((s1.total ?? 0) + (s2.total ?? 0)) * 100) / 100

    return {
      ...row,
      s1,
      s2,
      grandTotal,
      retakePercent: null,
    }
  })

  const totalsS1 = sumSemester(rows, 's1')
  const totalsS2 = sumSemester(rows, 's2')
  const maxGrandTotal = rows
    .filter((row) => row.kind === 'subject')
    .reduce((sum, row) => sum + row.semesterMax * 2, 0)

  const grandTotal = totalsS1.total !== null || totalsS2.total !== null
    ? Math.round(((totalsS1.total ?? 0) + (totalsS2.total ?? 0)) * 100) / 100
    : null

  const percentage = grandTotal !== null && maxGrandTotal > 0
    ? Math.round((grandTotal / maxGrandTotal) * 10000) / 100
    : null

  const maximaS1: CtebSemesterScores = {
    max: totalsS1.max,
    period1: rows.filter((r) => r.kind === 'subject').reduce((s, r) => s + r.periodMax, 0),
    period2: rows.filter((r) => r.kind === 'subject').reduce((s, r) => s + r.periodMax, 0),
    examMax: totalsS1.examMax,
    exam: null,
    total: totalsS1.max,
  }

  const maximaS2: CtebSemesterScores = {
    max: totalsS2.max,
    period1: maximaS1.period1,
    period2: maximaS1.period2,
    examMax: totalsS2.examMax,
    exam: null,
    total: totalsS2.max,
  }

  return {
    rows,
    totals: {
      maximaS1,
      maximaS2,
      totalsS1,
      totalsS2,
      grandTotal,
      percentage,
      maxGrandTotal,
    },
  }
}

export function formatBulletinPoints(value: number | null | undefined): string {
  if (value === null || value === undefined) return ''
  return Number.isInteger(value) ? String(value) : value.toFixed(2).replace('.', ',')
}

export function formatBulletinPercent(value: number | null | undefined): string {
  if (value === null || value === undefined) return ''
  return `${value.toFixed(2).replace('.', ',')} %`
}
