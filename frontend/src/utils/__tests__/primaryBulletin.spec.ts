import { describe, expect, it } from 'vitest'
import { buildPrimaryBulletinRows } from '../primaryBulletin'
import type { ReportCardData } from '../../types'

const emptyReport: ReportCardData = {
  student: { id: 1, full_name: 'Eleve Test' },
  term: { id: 1, name: '1er Trimestre' },
  subjects: [],
  period_averages: [
    { period_id: 1, name: '1ere periode', position: 1, average: null },
    { period_id: 2, name: '2e periode', position: 2, average: null },
  ],
  overall_average: null,
  total_coefficient: 0,
}

describe('primary bulletin rows', () => {
  it('keeps official exam and trimester maxima visible without matching subjects', () => {
    const bulletin = buildPrimaryBulletinRows([emptyReport, null, null], 'moyen')
    const firstSubject = bulletin.rows.find((row) => row.kind === 'subject')

    expect(firstSubject?.label).toBe('Exp. Orale & Vocabulaire')
    expect(firstSubject?.periodMax).toBe(10)
    expect(firstSubject?.t1.examMax).toBe(20)
    expect(firstSubject?.t1.max).toBe(40)
    expect(firstSubject?.t1.exam).toBeNull()
    expect(firstSubject?.t1.total).toBeNull()
  })

  it('uses the official terminal maxima for 5e and 6e primary bulletins', () => {
    const bulletin = buildPrimaryBulletinRows([emptyReport, null, null], 'terminal')
    const firstSubject = bulletin.rows.find((row) => row.kind === 'subject')

    expect(firstSubject?.label).toBe('Gram. & Conj.')
    expect(firstSubject?.t1.examMax).toBe(20)
    expect(firstSubject?.t1.max).toBe(40)
    expect(bulletin.totals.maxPerPeriod).toBe(310)
    expect(bulletin.totals.maximaT1.examMax).toBe(620)
    expect(bulletin.totals.maximaT1.max).toBe(1240)
    expect(bulletin.totals.maxGrandTotal).toBe(3720)
  })
})
