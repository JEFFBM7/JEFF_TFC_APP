/**
 * Bulletin officiel primaire début (1ère et 2ème années) — IGE/P.S./001.
 * Trois trimestres, deux périodes par trimestre.
 */

export type PrimaryRowKind = 'domain' | 'subdomain' | 'subject' | 'subtotal'

export interface PrimarySubjectRow {
  kind: PrimaryRowKind
  label: string
  aliases?: string[]
  trimesterMax: number
  periodMax: number
  examMax: number
}

export type PrimaryGradeYear = 1 | 2

export interface PrimaryBulletinMeta {
  gradeYear: PrimaryGradeYear
  title: string
}

export interface PrimaryLevelRef {
  name?: string | null
  abbreviation?: string | null
  cycle?: string | null
}

const PRIMARY_LEGACY_ALIASES = {
  languesNationales: ['langues congolaises', 'langue congolaise', 'lecture labiale', 'lecture ecriture', 'langues nationales'],
  francais: ['vocabulaire', 'expression orale', 'art et parole', 'francais'],
  mathematiques: ['mesure', 'formes geometriques', 'numeration', 'operations', 'problemes', 'mathematiques'],
  eveil: ['sciences eveil', "sciences d eveil", 'eveil scientifique'],
  artsPlastiques: ['arts plastiques'],
  musique: ['arts dramatiques', 'art dramatique', 'musique'],
  eps: ['education physique', 'eps', 'mobilite', 'sport'],
  civique: ['civique', 'morale', 'education civique'],
} as const

export const PRIMARY_BULLETIN_ROWS: PrimarySubjectRow[] = [
  { kind: 'domain', label: 'DOMAINE DES LANGUES', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subdomain', label: 'LANGUES CONGOLAISES', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Expression orale et langue des signes', aliases: [...PRIMARY_LEGACY_ALIASES.languesNationales], trimesterMax: 20, periodMax: 5, examMax: 10 },
  { kind: 'subject', label: 'Expression écrite et braille', aliases: [...PRIMARY_LEGACY_ALIASES.languesNationales], trimesterMax: 20, periodMax: 5, examMax: 10 },
  { kind: 'subdomain', label: 'FRANÇAIS', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Vocabulaire', aliases: [...PRIMARY_LEGACY_ALIASES.francais], trimesterMax: 20, periodMax: 5, examMax: 10 },
  { kind: 'subject', label: 'Expression orale / Art et parole', aliases: [...PRIMARY_LEGACY_ALIASES.francais], trimesterMax: 20, periodMax: 5, examMax: 10 },
  { kind: 'subdomain', label: 'LECTURE - ECRITURE', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Lecture et écriture en langue congolaise ou Lecture labiale', aliases: [...PRIMARY_LEGACY_ALIASES.languesNationales], trimesterMax: 20, periodMax: 5, examMax: 10 },
  { kind: 'subtotal', label: 'Sous-Total', trimesterMax: 100, periodMax: 25, examMax: 50 },

  { kind: 'domain', label: 'DOMAINE DES MATHEMATIQUES, SCIENCES ET TECHNOLOGIE', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subdomain', label: 'MATHEMATIQUES', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Mesure', aliases: [...PRIMARY_LEGACY_ALIASES.mathematiques], trimesterMax: 20, periodMax: 5, examMax: 10 },
  { kind: 'subject', label: 'Formes géométriques', aliases: [...PRIMARY_LEGACY_ALIASES.mathematiques], trimesterMax: 20, periodMax: 5, examMax: 10 },
  { kind: 'subject', label: 'Numération', aliases: [...PRIMARY_LEGACY_ALIASES.mathematiques], trimesterMax: 20, periodMax: 5, examMax: 10 },
  { kind: 'subject', label: 'Opérations', aliases: [...PRIMARY_LEGACY_ALIASES.mathematiques], trimesterMax: 20, periodMax: 5, examMax: 10 },
  { kind: 'subject', label: 'Problèmes', aliases: [...PRIMARY_LEGACY_ALIASES.mathematiques], trimesterMax: 20, periodMax: 5, examMax: 10 },
  { kind: 'subdomain', label: 'SCIENCES', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: "Sciences d'éveil", aliases: [...PRIMARY_LEGACY_ALIASES.eveil], trimesterMax: 20, periodMax: 5, examMax: 10 },
  { kind: 'subject', label: 'Technologie', aliases: ['technologie'], trimesterMax: 20, periodMax: 5, examMax: 10 },
  { kind: 'subtotal', label: 'Sous-Total', trimesterMax: 140, periodMax: 35, examMax: 70 },

  { kind: 'domain', label: "DOMAINE DE L'UNIVERS SOCIAL ET ENVIRONNEMENT", trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Education civique et morale', aliases: [...PRIMARY_LEGACY_ALIASES.civique, 'education civique et morale'], trimesterMax: 20, periodMax: 5, examMax: 10 },
  { kind: 'subject', label: 'Education à la santé et à l\'environnement', aliases: ['sante', 'environnement'], trimesterMax: 20, periodMax: 5, examMax: 10 },
  { kind: 'subtotal', label: 'Sous-Total', trimesterMax: 40, periodMax: 10, examMax: 20 },

  { kind: 'domain', label: 'DOMAINE DES ARTS', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Arts plastiques', aliases: [...PRIMARY_LEGACY_ALIASES.artsPlastiques], trimesterMax: 20, periodMax: 5, examMax: 10 },
  { kind: 'subject', label: 'Arts dramatiques', aliases: [...PRIMARY_LEGACY_ALIASES.musique], trimesterMax: 20, periodMax: 5, examMax: 10 },
  { kind: 'subtotal', label: 'Sous-Total', trimesterMax: 40, periodMax: 10, examMax: 20 },

  { kind: 'domain', label: 'DOMAINE DU DEVELOPPEMENT PERSONNEL', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Education physique et sport/mobilité', aliases: [...PRIMARY_LEGACY_ALIASES.eps], trimesterMax: 20, periodMax: 5, examMax: 10 },
  { kind: 'subject', label: 'Initiation aux travaux productifs', aliases: ['travaux productifs', 'initiation'], trimesterMax: 20, periodMax: 5, examMax: 10 },
  { kind: 'subject', label: 'Religion', aliases: ['religion'], trimesterMax: 20, periodMax: 5, examMax: 10 },
  { kind: 'subtotal', label: 'Sous-Total', trimesterMax: 60, periodMax: 15, examMax: 30 },
]

export function resolvePrimaryGradeYear(level?: PrimaryLevelRef | null): PrimaryGradeYear {
  const abbreviation = (level?.abbreviation ?? '').toUpperCase()
  if (abbreviation === '2P' || abbreviation.startsWith('2')) return 2
  return 1
}

export type PrimaryBulletinTier = 'debut' | 'moyen' | 'terminal'

export function resolvePrimaryTier(level?: PrimaryLevelRef | null): PrimaryBulletinTier | null {
  if (level?.cycle !== 'primaire') return null
  const abbreviation = (level?.abbreviation ?? '').toUpperCase()
  if (abbreviation === '1P' || abbreviation === '2P') return 'debut'
  if (abbreviation === '3P' || abbreviation === '4P') return 'moyen'
  if (abbreviation === '5P' || abbreviation === '6P') return 'terminal'
  return null
}

export function isPrimaireDebutLevel(level?: PrimaryLevelRef | null): boolean {
  return resolvePrimaryTier(level) === 'debut'
}

export function isOfficialPrimaireBulletinLevel(level?: PrimaryLevelRef | null): boolean {
  return resolvePrimaryTier(level) !== null
}

export function primaryBulletinMeta(level?: PrimaryLevelRef | null): PrimaryBulletinMeta {
  const gradeYear = resolvePrimaryGradeYear(level)
  const yearLabel = gradeYear === 1 ? '1ère' : '2ème'
  return {
    gradeYear,
    title: `BULLETIN — DEGRÉ ÉLÉMENTAIRE / ÉDUCATION SPÉCIALE (${yearLabel} année)`,
  }
}

export function getPrimaryBulletinRows(): PrimarySubjectRow[] {
  return PRIMARY_BULLETIN_ROWS
}

/** Maximum annuel (380 pts × 3 trimestres). */
export const PRIMARY_GRAND_TOTAL_MAX = 1140

export const PRIMARY_FORM_CODE = 'IGE/P.S./001'

export const PRIMARY_SCHOOL_DEFAULTS = {
  republic: 'RÉPUBLIQUE DÉMOCRATIQUE DU CONGO',
  ministry: "MINISTÈRE DE L'ÉDUCATION NATIONALE ET NOUVELLE CITOYENNETÉ",
  schoolName: 'Complexe scolaire MALUNGA',
  province: 'Province Éducationnelle',
  city: '',
  commune: '',
  schoolCode: '',
} as const
