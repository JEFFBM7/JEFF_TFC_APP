<script setup lang="ts">
import { computed } from 'vue'
import {
  PRIMARY_FORM_CODE,
  PRIMARY_SCHOOL_DEFAULTS,
  primaryBulletinMeta,
  resolvePrimaryTier,
} from '../../data/primaryBulletinStructure'
import {
  PRIMARY_MOYEN_FORM_CODE,
  primaryMoyenBulletinMeta,
} from '../../data/primaryMoyenBulletinStructure'
import {
  PRIMARY_TERMINAL_FORM_CODE,
  primaryTerminalBulletinMeta,
} from '../../data/primaryTerminalBulletinStructure'
import type { Student } from '../../types'
import type { ReportCardData } from '../../types'
import {
  buildPrimaryBulletinRows,
  formatBulletinPercent,
  formatBulletinPoints,
  primaryAnnualMax,
} from '../../utils/primaryBulletin'

const props = defineProps<{
  student: Student
  schoolYearName: string
  trimesterReports: [ReportCardData | null, ReportCardData | null, ReportCardData | null]
  rank?: number | null
  classSize?: number | null
  application?: string | null
  conduct?: string | null
  appreciation?: string | null
}>()

const tier = computed(() => resolvePrimaryTier(props.student.classroom?.level) ?? 'debut')
const meta = computed(() =>
  tier.value === 'terminal'
    ? primaryTerminalBulletinMeta(props.student.classroom?.level)
    : tier.value === 'moyen'
    ? primaryMoyenBulletinMeta(props.student.classroom?.level)
    : primaryBulletinMeta(props.student.classroom?.level),
)
const formCode = computed(() => {
  if (tier.value === 'terminal') return PRIMARY_TERMINAL_FORM_CODE
  return tier.value === 'moyen' ? PRIMARY_MOYEN_FORM_CODE : PRIMARY_FORM_CODE
})
const bulletin = computed(() => buildPrimaryBulletinRows(props.trimesterReports, tier.value))
const isTerminalGradeSix = computed(() => tier.value === 'terminal' && meta.value.gradeYear === 6)

const decisionPassed = computed(() => {
  const pct = bulletin.value.totals.percentage
  if (pct === null) return null
  return pct >= 50
})

function genderShort(gender?: Student['gender']): string {
  if (gender === 'F') return 'F'
  if (gender === 'M') return 'M'
  return ''
}

function formatBirthDate(value?: string | null): string {
  if (!value) return '… / … / …'
  const [y, m, d] = value.split('-')
  if (!y || !m || !d) return value
  return `${d} / ${m} / ${y}`
}

function rowClass(kind: string): string {
  if (kind === 'domain') return 'is-domain'
  if (kind === 'subdomain') return 'is-subdomain'
  if (kind === 'subtotal') return 'is-subtotal'
  return 'is-subject'
}

function showNumeric(kind: string): boolean {
  return kind === 'subject' || kind === 'subtotal'
}
</script>

<template>
  <article class="primaire-sheet" aria-label="Bulletin scolaire primaire">
    <div class="primaire-watermark" aria-hidden="true" />

    <header class="primaire-header">
      <div class="primaire-crest primaire-crest--flag" aria-hidden="true">
        <span class="flag-stripe blue" />
        <span class="flag-stripe yellow" />
        <span class="flag-stripe red" />
      </div>
      <div class="primaire-header-center">
        <p class="primaire-republic">{{ PRIMARY_SCHOOL_DEFAULTS.republic }}</p>
        <p class="primaire-ministry">{{ PRIMARY_SCHOOL_DEFAULTS.ministry }}</p>
      </div>
      <div class="primaire-crest primaire-crest--seal" aria-hidden="true">RDC</div>
    </header>

    <section class="primaire-id-block">
      <div class="primaire-id-row">
        <span>N° ID.</span>
        <span class="primaire-boxes">{{ student.registration_number ?? '………………' }}</span>
      </div>
      <div class="primaire-id-grid">
        <p><span>PROVINCE ÉDUCATIONNELLE :</span> {{ PRIMARY_SCHOOL_DEFAULTS.province }}</p>
        <p><span>VILLE :</span> {{ PRIMARY_SCHOOL_DEFAULTS.city || '…………' }}</p>
        <p><span>COMMUNE / TERRITOIRE :</span> {{ PRIMARY_SCHOOL_DEFAULTS.commune || '…………' }}</p>
        <p><span>ÉCOLE :</span> {{ PRIMARY_SCHOOL_DEFAULTS.schoolName }}</p>
        <p><span>CODE :</span> {{ PRIMARY_SCHOOL_DEFAULTS.schoolCode || '…………' }}</p>
      </div>
      <div class="primaire-student-grid">
        <p><span>ÉLÈVE :</span> <strong>{{ student.full_name }}</strong></p>
        <p><span>SEXE :</span> {{ genderShort(student.gender) || '—' }}</p>
        <p><span>NÉ(E) À</span> {{ student.place_of_birth || '…………' }} <span>LE</span> {{ formatBirthDate(student.date_of_birth) }}</p>
        <p><span>CLASSE :</span> {{ student.classroom?.full_name ?? '—' }}</p>
        <p><span>N° PERM. :</span> {{ student.order_number ?? '…………' }}</p>
      </div>
    </section>

    <h1 class="primaire-title">
      {{ meta.title }}
      <span>ANNÉE SCOLAIRE {{ schoolYearName.replace('-', ' – ') }}</span>
    </h1>

    <div class="primaire-table-wrap">
      <table class="primaire-table">
        <thead>
          <tr class="head-main">
            <th rowspan="2" class="col-branch">BRANCHES</th>
            <th colspan="7">PREMIER TRIMESTRE</th>
            <th colspan="6">DEUXIÈME TRIMESTRE</th>
            <th colspan="6">TROISIÈME TRIMESTRE</th>
            <th colspan="2">TOTAL</th>
          </tr>
          <tr class="head-cols">
            <th>MAX per</th>
            <th>1<sup>ère</sup> P.</th>
            <th>2<sup>e</sup> P.</th>
            <th>MAX EX.</th>
            <th>PTS OBT.</th>
            <th>MAX TRIM.</th>
            <th>PTS OBT.</th>
            <th>3<sup>e</sup> P.</th>
            <th>4<sup>e</sup> P.</th>
            <th>MAX EX.</th>
            <th>PTS OBT.</th>
            <th>MAX TRIM.</th>
            <th>PTS OBT.</th>
            <th>5<sup>e</sup> P.</th>
            <th>6<sup>e</sup> P.</th>
            <th>MAX EX.</th>
            <th>PTS OBT.</th>
            <th>MAX TRIM.</th>
            <th>PTS OBT.</th>
            <th>MAX</th>
            <th>PTS OBT.</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="(row, index) in bulletin.rows"
            :key="`${row.label}-${index}`"
            :class="rowClass(row.kind)"
          >
            <td class="col-branch">{{ row.label }}</td>
            <!-- 1er trimestre (7 colonnes) -->
            <td class="num">{{ showNumeric(row.kind) ? formatBulletinPoints(row.periodMax) : '' }}</td>
            <td class="num">{{ formatBulletinPoints(row.t1.period1) }}</td>
            <td class="num">{{ formatBulletinPoints(row.t1.period2) }}</td>
            <td class="num">{{ showNumeric(row.kind) ? formatBulletinPoints(row.t1.examMax) : '' }}</td>
            <td class="num">{{ formatBulletinPoints(row.t1.exam) }}</td>
            <td class="num">{{ showNumeric(row.kind) ? formatBulletinPoints(row.t1.max) : '' }}</td>
            <td class="num score">{{ formatBulletinPoints(row.t1.total) }}</td>
            <!-- 2e trimestre (6 colonnes) -->
            <td class="num">{{ formatBulletinPoints(row.t2.period1) }}</td>
            <td class="num">{{ formatBulletinPoints(row.t2.period2) }}</td>
            <td class="num">{{ showNumeric(row.kind) ? formatBulletinPoints(row.t2.examMax) : '' }}</td>
            <td class="num">{{ formatBulletinPoints(row.t2.exam) }}</td>
            <td class="num">{{ showNumeric(row.kind) ? formatBulletinPoints(row.t2.max) : '' }}</td>
            <td class="num score">{{ formatBulletinPoints(row.t2.total) }}</td>
            <!-- 3e trimestre (6 colonnes) -->
            <td class="num">{{ formatBulletinPoints(row.t3.period1) }}</td>
            <td class="num">{{ formatBulletinPoints(row.t3.period2) }}</td>
            <td class="num">{{ showNumeric(row.kind) ? formatBulletinPoints(row.t3.examMax) : '' }}</td>
            <td class="num">{{ formatBulletinPoints(row.t3.exam) }}</td>
            <td class="num">{{ showNumeric(row.kind) ? formatBulletinPoints(row.t3.max) : '' }}</td>
            <td class="num score">{{ formatBulletinPoints(row.t3.total) }}</td>
            <!-- Total annuel -->
            <td class="num">{{ showNumeric(row.kind) ? formatBulletinPoints(primaryAnnualMax(row)) : '' }}</td>
            <td class="num score">{{ formatBulletinPoints(row.grandTotal) }}</td>
          </tr>

          <tr class="summary-row">
            <td><strong>MAXIMA GÉNÉRAUX</strong></td>
            <td class="num">{{ formatBulletinPoints(bulletin.totals.maxPerPeriod) }}</td>
            <td colspan="2" />
            <td class="num">{{ formatBulletinPoints(bulletin.totals.maximaT1.examMax) }}</td>
            <td />
            <td class="num">{{ formatBulletinPoints(bulletin.totals.maximaT1.max) }}</td>
            <td />
            <td colspan="2" />
            <td class="num">{{ formatBulletinPoints(bulletin.totals.maximaT2.examMax) }}</td>
            <td />
            <td class="num">{{ formatBulletinPoints(bulletin.totals.maximaT2.max) }}</td>
            <td />
            <td colspan="2" />
            <td class="num">{{ formatBulletinPoints(bulletin.totals.maximaT3.examMax) }}</td>
            <td />
            <td class="num">{{ formatBulletinPoints(bulletin.totals.maximaT3.max) }}</td>
            <td />
            <td class="num">{{ formatBulletinPoints(bulletin.totals.maxGrandTotal) }}</td>
            <td />
          </tr>
          <tr class="summary-row">
            <td><strong>TOTAUX</strong></td>
            <td colspan="6" />
            <td class="num score">{{ formatBulletinPoints(bulletin.totals.totalsT1.total) }}</td>
            <td colspan="5" />
            <td class="num score">{{ formatBulletinPoints(bulletin.totals.totalsT2.total) }}</td>
            <td colspan="5" />
            <td class="num score">{{ formatBulletinPoints(bulletin.totals.totalsT3.total) }}</td>
            <td />
            <td class="num score">{{ formatBulletinPoints(bulletin.totals.grandTotal) }}</td>
          </tr>
          <tr class="summary-row">
            <td><strong>POURCENTAGE</strong></td>
            <td colspan="19" />
            <td colspan="2" class="num score"><strong>{{ formatBulletinPercent(bulletin.totals.percentage) }}</strong></td>
          </tr>
          <tr class="summary-row">
            <td><strong>PLACE</strong></td>
            <td colspan="19" />
            <td colspan="2" class="num">{{ rank ?? '' }}</td>
          </tr>
          <tr class="summary-row">
            <td><strong>NBRE D'ÉLÈVES</strong></td>
            <td colspan="19" />
            <td colspan="2" class="num">{{ classSize ?? '' }}</td>
          </tr>
          <tr class="summary-row">
            <td><strong>APPLICATION</strong></td>
            <td colspan="19" />
            <td colspan="2">{{ application ?? '' }}</td>
          </tr>
          <tr class="summary-row">
            <td><strong>CONDUITE</strong></td>
            <td colspan="19" />
            <td colspan="2">{{ conduct ?? '' }}</td>
          </tr>
          <tr class="summary-row">
            <td><strong>SIGNAT. DE L'INST.</strong></td>
            <td colspan="21" class="sign-line" />
          </tr>
          <tr class="summary-row">
            <td><strong>SIGNAT. DU RESP.</strong></td>
            <td colspan="21" class="sign-line" />
          </tr>
        </tbody>
      </table>
    </div>

    <footer class="primaire-footer">
      <div class="primaire-footer-col">
        <table v-if="isTerminalGradeSix" class="primaire-results-table">
          <caption>RÉSULTATS</caption>
          <thead>
            <tr>
              <th>Épreuve</th>
              <th>Point</th>
              <th>Sur</th>
            </tr>
          </thead>
          <tbody>
            <tr><td>Moyenne école</td><td /><td>50</td></tr>
            <tr><td>ENAFEP</td><td /><td>50</td></tr>
            <tr><td>Total</td><td /><td>100</td></tr>
          </tbody>
        </table>
        <div class="primaire-decision">
          <strong>DÉCISION</strong>
          <label><input type="checkbox" :checked="decisionPassed === true" disabled> Passe en classe supérieure</label>
          <label><input type="checkbox" :checked="decisionPassed === false" disabled> Redouble</label>
        </div>
      </div>
      <div class="primaire-footer-center">
        <p>Signature de l'élève</p>
        <div class="sign-box" />
        <p>Sceau de l'École</p>
        <div class="sign-box tall" />
      </div>
      <div class="primaire-footer-col">
        <p>Fait à ………………… le … / … / 20……</p>
        <p>Chef d'Établissement, Noms et Signature</p>
        <div class="sign-box tall" />
      </div>
    </footer>

    <p v-if="appreciation" class="primaire-appreciation">
      <strong>Appréciation :</strong> {{ appreciation }}
    </p>

    <p class="primaire-note">
      <strong>NOTE IMPORTANTE :</strong> Il est formellement interdit de reproduire ce bulletin. Le bulletin est sans valeur s'il est raturé ou surchargé.
      <span class="primaire-form-code">{{ formCode }}</span>
    </p>
  </article>
</template>

<style scoped>
.primaire-sheet {
  --primaire-ink: #111827;
  --primaire-border: #0f172a;
  position: relative;
  padding: 1rem;
  border: 2px solid var(--primaire-border);
  background: #fff;
  color: var(--primaire-ink);
  font-family: 'Segoe UI', 'DejaVu Sans', system-ui, sans-serif;
  font-size: 0.62rem;
  line-height: 1.25;
  overflow: hidden;
}

.primaire-watermark {
  position: absolute;
  inset: 28% 12% 22%;
  border: 3px solid rgb(15 23 42 / 0.06);
  border-radius: 50%;
  pointer-events: none;
}

.primaire-header {
  display: grid;
  grid-template-columns: 56px 1fr 56px;
  gap: 0.5rem;
  align-items: center;
  margin-bottom: 0.5rem;
}

.primaire-header-center { text-align: center; }
.primaire-republic { font-weight: 700; font-size: 0.72rem; margin: 0; }
.primaire-ministry { font-size: 0.62rem; margin: 0.15rem 0 0; }

.primaire-crest {
  width: 48px;
  height: 48px;
  border: 1px solid var(--primaire-border);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.primaire-crest--seal {
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 0.55rem;
  background: #f8fafc;
}

.flag-stripe { flex: 1; }
.flag-stripe.blue { background: #1d4ed8; }
.flag-stripe.yellow { background: #facc15; }
.flag-stripe.red { background: #dc2626; }

.primaire-id-block { margin-bottom: 0.5rem; font-size: 0.6rem; }
.primaire-id-row { display: flex; gap: 0.5rem; margin-bottom: 0.25rem; }
.primaire-boxes { letter-spacing: 0.15em; }
.primaire-id-grid,
.primaire-student-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.15rem 1rem;
}
.primaire-id-grid p,
.primaire-student-grid p { margin: 0; }
.primaire-id-grid span,
.primaire-student-grid span { font-weight: 600; }

.primaire-title {
  text-align: center;
  font-size: 0.68rem;
  font-weight: 700;
  border: 1px solid var(--primaire-border);
  padding: 0.35rem;
  margin: 0 0 0.5rem;
  text-transform: uppercase;
}
.primaire-title span { display: block; font-size: 0.6rem; margin-top: 0.15rem; }

.primaire-table-wrap { overflow-x: auto; }
.primaire-table {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  font-size: 0.5rem;
}
.primaire-table .head-cols th {
  font-size: 0.46rem;
  padding: 0.1rem 0.12rem;
  white-space: nowrap;
}
.primaire-table th,
.primaire-table td {
  border: 1px solid var(--primaire-border);
  padding: 0.15rem 0.2rem;
  text-align: center;
  vertical-align: middle;
}
.primaire-table .col-branch {
  text-align: left;
  width: 18%;
  min-width: 120px;
}
.primaire-table .col-total { width: 4%; }
.primaire-table .num { font-variant-numeric: tabular-nums; }
.primaire-table .score { color: #b91c1c; font-weight: 700; }
.primaire-table .sign-line { min-height: 1.25rem; }

.primaire-table .is-domain td { background: #dbeafe; font-weight: 700; text-transform: uppercase; }
.primaire-table .is-subdomain td { background: #eff6ff; font-style: italic; font-weight: 600; }
.primaire-table .is-subtotal td { background: #f8fafc; font-weight: 700; }
.primaire-table .summary-row td { font-weight: 600; }

.primaire-footer {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 1rem;
  margin-top: 0.75rem;
  font-size: 0.6rem;
}
.sign-box {
  border: 1px solid var(--primaire-border);
  min-height: 2.5rem;
  margin: 0.25rem 0 0.5rem;
}
.sign-box.tall { min-height: 4rem; }

.primaire-decision label {
  display: block;
  margin-top: 0.25rem;
}

.primaire-results-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 0.5rem;
  font-size: 0.52rem;
}
.primaire-results-table caption {
  border: 1px solid var(--primaire-border);
  border-bottom: none;
  font-weight: 700;
  text-align: left;
  padding: 0.12rem 0.2rem;
  text-transform: uppercase;
}
.primaire-results-table th,
.primaire-results-table td {
  border: 1px solid var(--primaire-border);
  padding: 0.12rem 0.2rem;
  text-align: center;
}
.primaire-results-table th:first-child,
.primaire-results-table td:first-child {
  text-align: left;
}

.primaire-appreciation {
  margin-top: 0.5rem;
  font-size: 0.62rem;
  border-top: 1px dashed var(--primaire-border);
  padding-top: 0.35rem;
}

.primaire-note {
  margin-top: 0.5rem;
  text-align: center;
  font-size: 0.55rem;
  font-weight: 600;
}
.primaire-form-code {
  float: right;
  font-weight: 700;
}

@media print {
  .primaire-sheet {
    border: none;
    padding: 0;
    font-size: 7pt;
  }
}
</style>
