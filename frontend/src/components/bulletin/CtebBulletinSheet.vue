<script setup lang="ts">
import { computed } from 'vue'
import {
  CTEB_FORM_CODE,
  CTEB_SCHOOL_DEFAULTS,
  ctebBulletinMeta,
} from '../../data/ctebBulletinStructure'
import type { Student } from '../../types'
import type { ReportCardData } from '../../types'
import {
  buildCtebBulletinRows,
  formatBulletinPercent,
  formatBulletinPoints,
} from '../../utils/ctebBulletin'

const props = defineProps<{
  student: Student
  schoolYearName: string
  semesterReports: [ReportCardData | null, ReportCardData | null]
  rank?: number | null
  classSize?: number | null
  application?: string | null
  conduct?: string | null
  appreciation?: string | null
}>()

const meta = computed(() => ctebBulletinMeta(props.student.classroom?.level))
const bulletin = computed(() => buildCtebBulletinRows(props.semesterReports, meta.value.gradeYear))

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
</script>

<template>
  <article class="cteb-sheet" aria-label="Bulletin scolaire CTEB">
    <div class="cteb-watermark" aria-hidden="true" />

    <header class="cteb-header">
      <div class="cteb-crest cteb-crest--flag" aria-hidden="true">
        <span class="flag-stripe blue" />
        <span class="flag-stripe yellow" />
        <span class="flag-stripe red" />
      </div>
      <div class="cteb-header-center">
        <p class="cteb-republic">{{ CTEB_SCHOOL_DEFAULTS.republic }}</p>
        <p class="cteb-ministry">{{ CTEB_SCHOOL_DEFAULTS.ministry }}</p>
      </div>
      <div class="cteb-crest cteb-crest--seal" aria-hidden="true">RDC</div>
    </header>

    <section class="cteb-id-block">
      <div class="cteb-id-row">
        <span>N° ID.</span>
        <span class="cteb-boxes">{{ student.registration_number ?? '………………' }}</span>
      </div>
      <div class="cteb-id-grid">
        <p><span>PROVINCE ÉDUCATIONNELLE :</span> {{ CTEB_SCHOOL_DEFAULTS.province }}</p>
        <p><span>VILLE :</span> {{ CTEB_SCHOOL_DEFAULTS.city || '…………' }}</p>
        <p><span>COMMUNE / TERRITOIRE :</span> {{ CTEB_SCHOOL_DEFAULTS.commune || '…………' }}</p>
        <p><span>ÉCOLE :</span> {{ CTEB_SCHOOL_DEFAULTS.schoolName }}</p>
        <p><span>CODE :</span> {{ CTEB_SCHOOL_DEFAULTS.schoolCode || '…………' }}</p>
      </div>
      <div class="cteb-student-grid">
        <p><span>ÉLÈVE :</span> <strong>{{ student.full_name }}</strong></p>
        <p><span>SEXE :</span> {{ genderShort(student.gender) || '—' }}</p>
        <p><span>NÉ(E) À</span> {{ student.place_of_birth || '…………' }} <span>LE</span> {{ formatBirthDate(student.date_of_birth) }}</p>
        <p><span>CLASSE :</span> {{ student.classroom?.full_name ?? '—' }}</p>
        <p><span>N° PERM. :</span> {{ student.order_number ?? '…………' }}</p>
      </div>
    </section>

    <h1 class="cteb-title">
      {{ meta.title }}
      <span>ANNÉE SCOLAIRE {{ schoolYearName.replace('-', ' – ') }}</span>
    </h1>

    <div class="cteb-table-wrap">
      <table class="cteb-table">
        <thead>
          <tr class="head-main">
            <th rowspan="3" class="col-branch">BRANCHES</th>
            <th colspan="5">PREMIER SEMESTRE</th>
            <th colspan="5">SECOND SEMESTRE</th>
            <th rowspan="3" class="col-total">TOTAL<br>GÉNÉRAL</th>
            <th colspan="2" rowspan="2">EXAMEN DE<br>REPECHAGE</th>
          </tr>
          <tr class="head-sub">
            <th rowspan="2">MAX.</th>
            <th colspan="2">TRAVAUX JOURNAL.</th>
            <th rowspan="2">MAX.<br>EXAM.</th>
            <th rowspan="2">TOTAL</th>
            <th rowspan="2">MAX.</th>
            <th colspan="2">TRAVAUX JOURNAL.</th>
            <th rowspan="2">MAX.<br>EXAM.</th>
            <th rowspan="2">TOTAL</th>
          </tr>
          <tr class="head-period">
            <th>1<sup>ère</sup> P</th>
            <th>2<sup>ème</sup> P</th>
            <th>3<sup>ème</sup> P</th>
            <th>4<sup>ème</sup> P</th>
            <th>%</th>
            <th>Sign. Prof.</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="(row, index) in bulletin.rows"
            :key="`${row.label}-${index}`"
            :class="rowClass(row.kind)"
          >
            <td class="col-branch">{{ row.label }}</td>
            <td class="num">{{ row.kind === 'subject' || row.kind === 'subtotal' ? formatBulletinPoints(row.s1.max) : '' }}</td>
            <td class="num">{{ formatBulletinPoints(row.s1.period1) }}</td>
            <td class="num">{{ formatBulletinPoints(row.s1.period2) }}</td>
            <td class="num">{{ row.kind === 'subject' || row.kind === 'subtotal' ? formatBulletinPoints(row.s1.examMax) : '' }}</td>
            <td class="num score">{{ formatBulletinPoints(row.s1.total) }}</td>
            <td class="num">{{ row.kind === 'subject' || row.kind === 'subtotal' ? formatBulletinPoints(row.s2.max) : '' }}</td>
            <td class="num">{{ formatBulletinPoints(row.s2.period1) }}</td>
            <td class="num">{{ formatBulletinPoints(row.s2.period2) }}</td>
            <td class="num">{{ row.kind === 'subject' || row.kind === 'subtotal' ? formatBulletinPoints(row.s2.examMax) : '' }}</td>
            <td class="num score">{{ formatBulletinPoints(row.s2.total) }}</td>
            <td class="num score">{{ formatBulletinPoints(row.grandTotal) }}</td>
            <td class="num">{{ formatBulletinPercent(row.retakePercent) }}</td>
            <td class="sign" />
          </tr>

          <tr class="summary-row">
            <td><strong>MAXIMA GENERAUX</strong></td>
            <td class="num">{{ formatBulletinPoints(bulletin.totals.maximaS1.max) }}</td>
            <td class="num">{{ formatBulletinPoints(bulletin.totals.maximaS1.period1) }}</td>
            <td class="num">{{ formatBulletinPoints(bulletin.totals.maximaS1.period2) }}</td>
            <td class="num">{{ formatBulletinPoints(bulletin.totals.maximaS1.examMax) }}</td>
            <td class="num">{{ formatBulletinPoints(bulletin.totals.maximaS1.total) }}</td>
            <td class="num">{{ formatBulletinPoints(bulletin.totals.maximaS2.max) }}</td>
            <td class="num">{{ formatBulletinPoints(bulletin.totals.maximaS2.period1) }}</td>
            <td class="num">{{ formatBulletinPoints(bulletin.totals.maximaS2.period2) }}</td>
            <td class="num">{{ formatBulletinPoints(bulletin.totals.maximaS2.examMax) }}</td>
            <td class="num">{{ formatBulletinPoints(bulletin.totals.maximaS2.total) }}</td>
            <td class="num">{{ formatBulletinPoints(bulletin.totals.maxGrandTotal) }}</td>
            <td colspan="2" />
          </tr>
          <tr class="summary-row">
            <td><strong>TOTAUX</strong></td>
            <td class="num" colspan="4" />
            <td class="num score">{{ formatBulletinPoints(bulletin.totals.totalsS1.total) }}</td>
            <td class="num" colspan="4" />
            <td class="num score">{{ formatBulletinPoints(bulletin.totals.totalsS2.total) }}</td>
            <td class="num score">{{ formatBulletinPoints(bulletin.totals.grandTotal) }}</td>
            <td colspan="2" />
          </tr>
          <tr class="summary-row">
            <td><strong>POURCENTAGE</strong></td>
            <td colspan="10" />
            <td class="num score"><strong>{{ formatBulletinPercent(bulletin.totals.percentage) }}</strong></td>
            <td colspan="2" />
          </tr>
          <tr class="summary-row">
            <td><strong>PLACE / NBRE D'ÉLÈVES</strong></td>
            <td colspan="10" />
            <td class="num">
              <template v-if="rank && classSize">{{ rank }} / {{ classSize }}</template>
            </td>
            <td colspan="2" />
          </tr>
          <tr class="summary-row">
            <td><strong>APPLICATION</strong></td>
            <td colspan="10" />
            <td>{{ application ?? '' }}</td>
            <td colspan="2" />
          </tr>
          <tr class="summary-row">
            <td><strong>CONDUITE</strong></td>
            <td colspan="10" />
            <td>{{ conduct ?? '' }}</td>
            <td colspan="2" />
          </tr>
          <tr class="summary-row">
            <td><strong>SIGNATURE</strong></td>
            <td colspan="13" class="sign-line" />
          </tr>
        </tbody>
      </table>
    </div>

    <footer class="cteb-footer" :class="{ 'cteb-footer--grade-8': meta.gradeYear === 8 }">
      <div class="cteb-footer-col">
        <table v-if="meta.gradeYear === 8" class="cteb-mini-table">
          <caption>RÉSULTAT FINAL</caption>
          <tbody>
            <tr><td>Moyenne École</td><td>50</td><td>{{ formatBulletinPercent(bulletin.totals.percentage) }}</td></tr>
            <tr><td>TENASOSP</td><td>50</td><td /></tr>
            <tr><td>Total</td><td>100</td><td>{{ formatBulletinPoints(bulletin.totals.grandTotal) }}</td></tr>
          </tbody>
        </table>
        <div v-if="meta.gradeYear === 8" class="cteb-decision">
          <strong>DÉCISION DU JURY</strong>
          <label><input type="checkbox" :checked="decisionPassed === true" disabled> Passe (1)</label>
          <label><input type="checkbox" :checked="decisionPassed === false" disabled> Double (1)</label>
        </div>
        <p v-if="meta.gradeYear === 8" class="cteb-option-line"><strong>Option orientée :</strong> …………………………………………</p>
        <table v-if="meta.gradeYear === 8" class="cteb-mini-table">
          <caption>Membre de la commission</caption>
          <thead>
            <tr><th>Fonction</th><th>Nom</th><th>Signature</th></tr>
          </thead>
          <tbody>
            <tr><td>Superviseur</td><td /><td /></tr>
            <tr><td>Superviseur adjoint</td><td /><td /></tr>
            <tr><td>Président</td><td /><td /></tr>
          </tbody>
        </table>
      </div>

      <div class="cteb-footer-center">
        <p>Signature de l'élève</p>
        <div class="sign-box" />
        <template v-if="meta.gradeYear === 7">
          <p>Sceau de l'École</p>
          <div class="sign-box" />
          <p>Chef d'Établissement, Noms et Signature</p>
          <div class="sign-box tall" />
        </template>
      </div>

      <div class="cteb-footer-col">
        <template v-if="meta.gradeYear === 8">
          <p>Fait à ………………… le … / … / 20……</p>
          <p>Sceau de l'École et signature du Préfet des études</p>
          <div class="sign-box tall" />
          <p>Sceau du Pool d'Inspection</p>
          <div class="sign-box" />
        </template>
        <template v-else>
          <p>Fait à ………………… le … / … / 20……</p>
          <div class="cteb-decision cteb-decision--side">
            <label><input type="checkbox" :checked="decisionPassed === true" disabled> Passe (1)</label>
            <label><input type="checkbox" :checked="decisionPassed === false" disabled> Double (1)</label>
          </div>
          <p>Sceau de l'École</p>
          <div class="sign-box tall" />
        </template>
      </div>
    </footer>

    <p v-if="appreciation" class="cteb-appreciation">
      <strong>Appréciation :</strong> {{ appreciation }}
    </p>

    <p class="cteb-note">
      <strong>NOTE IMPORTANTE :</strong> Le bulletin est sans valeur s'il est raturé ou surchargé.
      <span class="cteb-form-code">{{ CTEB_FORM_CODE[meta.gradeYear] }}</span>
    </p>
  </article>
</template>

<style scoped>
.cteb-sheet {
  --cteb-ink: #111827;
  --cteb-border: #0f172a;
  --cteb-accent: #1d4ed8;
  position: relative;
  padding: 1rem;
  border: 2px solid var(--cteb-border);
  background: #fff;
  color: var(--cteb-ink);
  font-family: 'Segoe UI', 'DejaVu Sans', system-ui, sans-serif;
  font-size: 0.68rem;
  line-height: 1.25;
  overflow: hidden;
}

.cteb-watermark {
  position: absolute;
  inset: 28% 18% 22%;
  border: 3px solid rgb(15 23 42 / 0.06);
  border-radius: 50%;
  pointer-events: none;
}

.cteb-watermark::after {
  content: 'RDC';
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  font-size: 4.5rem;
  font-weight: 900;
  color: rgb(15 23 42 / 0.05);
  letter-spacing: 0.2em;
}

.cteb-header {
  display: grid;
  grid-template-columns: 3.5rem 1fr 3.5rem;
  gap: 0.75rem;
  align-items: center;
  margin-bottom: 0.65rem;
}

.cteb-crest {
  width: 3.5rem;
  height: 3.5rem;
  border: 1px solid var(--cteb-border);
  display: grid;
  place-items: center;
  font-weight: 900;
  font-size: 0.72rem;
}

.cteb-crest--flag {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  padding: 0;
}

.flag-stripe {
  flex: 1;
  width: 100%;
}

.flag-stripe.blue { background: #2563eb; }
.flag-stripe.yellow { background: #facc15; }
.flag-stripe.red { background: #dc2626; }

.cteb-header-center {
  text-align: center;
}

.cteb-republic,
.cteb-ministry {
  margin: 0;
  font-weight: 800;
  letter-spacing: 0.04em;
}

.cteb-republic { font-size: 0.78rem; }
.cteb-ministry { font-size: 0.62rem; margin-top: 0.15rem; }

.cteb-id-block {
  display: grid;
  gap: 0.35rem;
  margin-bottom: 0.55rem;
  font-size: 0.62rem;
}

.cteb-id-row,
.cteb-id-grid,
.cteb-student-grid {
  display: grid;
  gap: 0.2rem 0.75rem;
}

.cteb-id-grid {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.cteb-student-grid {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.cteb-id-block span {
  font-weight: 700;
}

.cteb-boxes {
  font-family: ui-monospace, monospace;
  letter-spacing: 0.12em;
}

.cteb-title {
  margin: 0 0 0.55rem;
  padding: 0.35rem 0.5rem;
  border: 1px solid var(--cteb-border);
  text-align: center;
  font-size: 0.72rem;
  font-weight: 900;
  letter-spacing: 0.02em;
  background: linear-gradient(180deg, #f8fafc, #eef2ff);
}

.cteb-title span {
  display: block;
  margin-top: 0.15rem;
  font-size: 0.66rem;
}

.cteb-table-wrap {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

.cteb-table {
  width: 100%;
  min-width: 58rem;
  border-collapse: collapse;
  table-layout: fixed;
  font-size: 0.58rem;
}

.cteb-table th,
.cteb-table td {
  border: 1px solid var(--cteb-border);
  padding: 0.18rem 0.22rem;
  vertical-align: middle;
  word-break: break-word;
}

.cteb-table thead th {
  background: #f1f5f9;
  font-weight: 800;
  text-align: center;
}

.col-branch {
  width: 11rem;
  text-align: left;
  font-weight: 700;
}

.num {
  text-align: center;
  font-variant-numeric: tabular-nums;
}

.score {
  color: #b91c1c;
  font-weight: 800;
}

.sign {
  min-width: 2.5rem;
}

.is-domain td {
  background: #dbeafe;
  font-weight: 900;
  text-transform: uppercase;
  font-size: 0.6rem;
}

.is-subdomain td {
  background: #eff6ff;
  font-weight: 800;
  font-style: italic;
}

.is-subtotal td {
  background: #f8fafc;
  font-weight: 800;
}

.summary-row td {
  background: #fafafa;
  font-weight: 700;
}

.sign-line {
  min-height: 1.25rem;
}

.cteb-footer {
  display: grid;
  grid-template-columns: 1.1fr 0.7fr 1fr;
  gap: 0.75rem;
  margin-top: 0.75rem;
  font-size: 0.6rem;
}

.cteb-mini-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 0.45rem;
}

.cteb-mini-table caption {
  caption-side: top;
  text-align: left;
  font-weight: 800;
  margin-bottom: 0.2rem;
}

.cteb-mini-table th,
.cteb-mini-table td {
  border: 1px solid var(--cteb-border);
  padding: 0.2rem 0.3rem;
}

.cteb-decision {
  display: grid;
  gap: 0.2rem;
  margin-bottom: 0.45rem;
}

.cteb-decision label {
  display: flex;
  align-items: center;
  gap: 0.35rem;
}

.cteb-footer-center,
.cteb-footer-col {
  display: grid;
  align-content: start;
  gap: 0.35rem;
}

.sign-box {
  min-height: 2.5rem;
  border: 1px dashed #94a3b8;
  border-radius: 2px;
}

.sign-box.tall {
  min-height: 3.5rem;
}

.cteb-appreciation {
  margin: 0.65rem 0 0;
  padding: 0.45rem 0.55rem;
  border: 1px solid #cbd5e1;
  background: #f8fafc;
  font-size: 0.62rem;
}

.cteb-note {
  margin: 0.5rem 0 0;
  font-size: 0.58rem;
  font-weight: 700;
  text-align: center;
}

.cteb-form-code {
  display: block;
  margin-top: 0.2rem;
  font-weight: 800;
  letter-spacing: 0.04em;
}

.cteb-option-line {
  margin: 0 0 0.45rem;
}

.cteb-decision--side {
  margin: 0.35rem 0;
}

@media print {
  .cteb-sheet {
    border: none;
    padding: 0;
    box-shadow: none;
  }

  .cteb-table-wrap {
    overflow: visible;
  }
}

@media (max-width: 900px) {
  .cteb-footer {
    grid-template-columns: 1fr;
  }

  .cteb-id-grid,
  .cteb-student-grid {
    grid-template-columns: 1fr;
  }
}
</style>
