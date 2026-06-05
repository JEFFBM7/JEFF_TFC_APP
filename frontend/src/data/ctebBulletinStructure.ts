/**
 * Structure officielle du bulletin CTEB 7e / 8e (RDC).
 * Les maxima diffèrent entre la 7e et la 8e année.
 */

export type CtebRowKind = 'domain' | 'subdomain' | 'subject' | 'subtotal'

export interface CtebSubjectRow {
  kind: CtebRowKind
  label: string
  /** Libellés alternatifs pour rapprocher les matières de l'application. */
  aliases?: string[]
  /** Maximum du semestre (colonne Total). */
  semesterMax: number
  /** Maximum par période (1ère P / 2ème P ou 3ème P / 4ème P). */
  periodMax: number
  /** Maximum examen de semestre. */
  examMax: number
}

export type CtebGradeYear = 7 | 8

export interface CtebBulletinMeta {
  gradeYear: CtebGradeYear
  title: string
}

export interface CtebLevelRef {
  name?: string | null
  abbreviation?: string | null
}

export function resolveCtebGradeYear(level?: CtebLevelRef | null): CtebGradeYear {
  const abbreviation = (level?.abbreviation ?? '').toUpperCase()
  if (abbreviation === '8EB' || abbreviation.startsWith('8')) return 8
  if (abbreviation === '7EB' || abbreviation.startsWith('7')) return 7

  const normalized = (level?.name ?? '').toLowerCase()
  if (normalized.includes('8')) return 8
  return 7
}

export function ctebBulletinMeta(level?: CtebLevelRef | null): CtebBulletinMeta {
  const gradeYear = resolveCtebGradeYear(level)
  return {
    gradeYear,
    title: `BULLETIN DE LA ${gradeYear}ème ANNÉE CYCLE TERMINAL DE L'ÉDUCATION DE BASE (CTEB)`,
  }
}

const SUBJECT_ALIASES = {
  arithmetique: ['arithmetique', 'math arithmétique', 'mathématiques'],
  statistique: ['statistiques'],
  geometrie: ['geometrie'],
  algebre: ['algebre'],
  anatomie: ['biologie'],
  physique: ['physique'],
  tic: ['tic', 'informatique', 'technologie information', 'techn. d info com'],
  anglais: ['english'],
  francais: ['francais'],
  educationVie: ['éducation à la vie', 'education a la vie', 'ed. a la vie'],
  civique: ['civique', 'morale', 'éducation civique et morale'],
  geographie: ['geographie'],
  dessin: ['arts plastiques'],
  eps: ['eps', 'sport', 'éducation physique'],
} as const

/** Grille nationale 7e CTEB (IGE/P.S./007 — total annuel 3200 pts). */
export const CTEB_BULLETIN_ROWS_7: CtebSubjectRow[] = [
  { kind: 'domain', label: 'DOMAINE DES SCIENCES', semesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subdomain', label: 'Sous-domaine des Mathématiques', semesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Arithmétique', aliases: [...SUBJECT_ALIASES.arithmetique], semesterMax: 40, periodMax: 5, examMax: 20 },
  { kind: 'subject', label: 'Statistique', aliases: [...SUBJECT_ALIASES.statistique], semesterMax: 40, periodMax: 5, examMax: 20 },
  { kind: 'subject', label: 'Géométrie', aliases: [...SUBJECT_ALIASES.geometrie], semesterMax: 80, periodMax: 10, examMax: 40 },
  { kind: 'subject', label: 'Algèbre', aliases: [...SUBJECT_ALIASES.algebre], semesterMax: 160, periodMax: 20, examMax: 80 },
  { kind: 'subtotal', label: 'Sous-Total', semesterMax: 320, periodMax: 40, examMax: 160 },

  { kind: 'subdomain', label: 'Sous-domaine des Sciences de la Vie et de la Terre', semesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Anatomie', aliases: [...SUBJECT_ALIASES.anatomie], semesterMax: 40, periodMax: 5, examMax: 20 },
  { kind: 'subject', label: 'Botanique', semesterMax: 40, periodMax: 5, examMax: 20 },
  { kind: 'subject', label: 'Zoologie', semesterMax: 40, periodMax: 5, examMax: 20 },
  { kind: 'subtotal', label: 'Sous-Total', semesterMax: 120, periodMax: 15, examMax: 60 },

  { kind: 'subdomain', label: 'Sous-domaine des Sciences Physiques, Technologie et TIC', semesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Sciences Physiques', aliases: [...SUBJECT_ALIASES.physique], semesterMax: 40, periodMax: 5, examMax: 20 },
  { kind: 'subject', label: 'Technologie', semesterMax: 40, periodMax: 5, examMax: 20 },
  { kind: 'subject', label: "Techn. d'Info. & Com (TIC)", aliases: [...SUBJECT_ALIASES.tic], semesterMax: 40, periodMax: 5, examMax: 20 },
  { kind: 'subtotal', label: 'Sous-Total', semesterMax: 120, periodMax: 15, examMax: 60 },

  { kind: 'domain', label: 'DOMAINE DES LANGUES', semesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Anglais', aliases: [...SUBJECT_ALIASES.anglais], semesterMax: 120, periodMax: 15, examMax: 60 },
  { kind: 'subject', label: 'Français', aliases: [...SUBJECT_ALIASES.francais], semesterMax: 280, periodMax: 35, examMax: 140 },
  { kind: 'subtotal', label: 'Sous-Total', semesterMax: 400, periodMax: 50, examMax: 200 },

  { kind: 'domain', label: "DOMAINE DE L'UNIVERS SOCIAL ET ENVIRONNEMENT", semesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Religion', semesterMax: 80, periodMax: 10, examMax: 40 },
  { kind: 'subject', label: 'Education à la vie', aliases: [...SUBJECT_ALIASES.educationVie], semesterMax: 80, periodMax: 10, examMax: 40 },
  { kind: 'subject', label: 'Ed. civique et morale', aliases: [...SUBJECT_ALIASES.civique], semesterMax: 80, periodMax: 10, examMax: 40 },
  { kind: 'subject', label: 'Histoire', semesterMax: 80, periodMax: 10, examMax: 40 },
  { kind: 'subject', label: 'Géographie', aliases: [...SUBJECT_ALIASES.geographie], semesterMax: 80, periodMax: 10, examMax: 40 },
  { kind: 'subtotal', label: 'Sous-Total', semesterMax: 400, periodMax: 50, examMax: 200 },

  { kind: 'domain', label: 'DOMAINE DES ARTS', semesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Dessin', aliases: [...SUBJECT_ALIASES.dessin], semesterMax: 80, periodMax: 10, examMax: 40 },
  { kind: 'subject', label: 'Musique', semesterMax: 80, periodMax: 10, examMax: 40 },
  { kind: 'subtotal', label: 'Sous-Total', semesterMax: 160, periodMax: 20, examMax: 80 },

  { kind: 'domain', label: 'DOMAINE DU DÉVELOPPEMENT PERSONNEL', semesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Ed. Physique & Sportive', aliases: [...SUBJECT_ALIASES.eps], semesterMax: 80, periodMax: 10, examMax: 40 },
  { kind: 'subtotal', label: 'Sous-Total', semesterMax: 80, periodMax: 10, examMax: 40 },
]

/** Grille nationale 8e CTEB (IGE/P.S./008 — total annuel 3360 pts). */
export const CTEB_BULLETIN_ROWS_8: CtebSubjectRow[] = [
  { kind: 'domain', label: 'DOMAINE DES SCIENCES', semesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subdomain', label: 'Sous-domaine des Mathématiques', semesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Arithmétique', aliases: [...SUBJECT_ALIASES.arithmetique], semesterMax: 40, periodMax: 5, examMax: 20 },
  { kind: 'subject', label: 'Statistique', aliases: [...SUBJECT_ALIASES.statistique], semesterMax: 40, periodMax: 5, examMax: 20 },
  { kind: 'subject', label: 'Géométrie', aliases: [...SUBJECT_ALIASES.geometrie], semesterMax: 80, periodMax: 10, examMax: 40 },
  { kind: 'subject', label: 'Algèbre', aliases: [...SUBJECT_ALIASES.algebre], semesterMax: 160, periodMax: 20, examMax: 80 },
  { kind: 'subtotal', label: 'Sous-Total', semesterMax: 320, periodMax: 40, examMax: 160 },

  { kind: 'subdomain', label: 'Sous-domaine des Sciences de la Vie et de la Terre', semesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Anatomie', aliases: [...SUBJECT_ALIASES.anatomie], semesterMax: 40, periodMax: 5, examMax: 20 },
  { kind: 'subject', label: 'Botanique', semesterMax: 40, periodMax: 5, examMax: 20 },
  { kind: 'subject', label: 'Nutrition', semesterMax: 40, periodMax: 5, examMax: 20 },
  { kind: 'subject', label: 'Zoologie', semesterMax: 80, periodMax: 10, examMax: 40 },
  { kind: 'subtotal', label: 'Sous-Total', semesterMax: 200, periodMax: 25, examMax: 100 },

  { kind: 'subdomain', label: 'Sous-domaine des Sciences Physiques, Technologie et TIC', semesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Chimie', semesterMax: 40, periodMax: 5, examMax: 20 },
  { kind: 'subject', label: 'Sciences Physiques', aliases: [...SUBJECT_ALIASES.physique], semesterMax: 40, periodMax: 5, examMax: 20 },
  { kind: 'subject', label: 'Technologie', semesterMax: 40, periodMax: 5, examMax: 20 },
  { kind: 'subject', label: "Techn. d'Info. & Com (TIC)", aliases: [...SUBJECT_ALIASES.tic], semesterMax: 40, periodMax: 5, examMax: 20 },
  { kind: 'subtotal', label: 'Sous-Total', semesterMax: 160, periodMax: 20, examMax: 80 },

  { kind: 'domain', label: 'DOMAINE DES LANGUES', semesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Anglais', aliases: [...SUBJECT_ALIASES.anglais], semesterMax: 120, periodMax: 15, examMax: 60 },
  { kind: 'subject', label: 'Français', aliases: [...SUBJECT_ALIASES.francais], semesterMax: 200, periodMax: 25, examMax: 100 },
  { kind: 'subtotal', label: 'Sous-Total', semesterMax: 320, periodMax: 40, examMax: 160 },

  { kind: 'domain', label: "DOMAINE DE L'UNIVERS SOCIAL ET ENVIRONNEMENT", semesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Religion', semesterMax: 80, periodMax: 10, examMax: 40 },
  { kind: 'subject', label: 'Education à la vie', aliases: [...SUBJECT_ALIASES.educationVie], semesterMax: 80, periodMax: 10, examMax: 40 },
  { kind: 'subject', label: 'Ed. civique et morale', aliases: [...SUBJECT_ALIASES.civique], semesterMax: 80, periodMax: 10, examMax: 40 },
  { kind: 'subject', label: 'Histoire', semesterMax: 80, periodMax: 10, examMax: 40 },
  { kind: 'subject', label: 'Géographie', aliases: [...SUBJECT_ALIASES.geographie], semesterMax: 120, periodMax: 15, examMax: 60 },
  { kind: 'subtotal', label: 'Sous-Total', semesterMax: 440, periodMax: 55, examMax: 220 },

  { kind: 'domain', label: 'DOMAINE DES ARTS', semesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Dessin', aliases: [...SUBJECT_ALIASES.dessin], semesterMax: 80, periodMax: 10, examMax: 40 },
  { kind: 'subject', label: 'Musique', semesterMax: 80, periodMax: 10, examMax: 40 },
  { kind: 'subtotal', label: 'Sous-Total', semesterMax: 160, periodMax: 20, examMax: 80 },

  { kind: 'domain', label: 'DOMAINE DU DÉVELOPPEMENT PERSONNEL', semesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Ed. Physique & Sportive', aliases: [...SUBJECT_ALIASES.eps], semesterMax: 80, periodMax: 10, examMax: 40 },
  { kind: 'subtotal', label: 'Sous-Total', semesterMax: 80, periodMax: 10, examMax: 40 },
]

export function getCtebBulletinRows(gradeYear: CtebGradeYear = 7): CtebSubjectRow[] {
  return gradeYear === 8 ? CTEB_BULLETIN_ROWS_8 : CTEB_BULLETIN_ROWS_7
}

/** @deprecated Préférer getCtebBulletinRows(gradeYear) */
export const CTEB_BULLETIN_ROWS = CTEB_BULLETIN_ROWS_7

export const CTEB_GRAND_TOTAL_MAX: Record<CtebGradeYear, number> = {
  7: 3200,
  8: 3360,
}

export const CTEB_FORM_CODE: Record<CtebGradeYear, string> = {
  7: 'IGE/P.S./007',
  8: 'IGE/P.S./008',
}

export const CTEB_SCHOOL_DEFAULTS = {
  republic: 'RÉPUBLIQUE DÉMOCRATIQUE DU CONGO',
  ministry: "MINISTÈRE DE L'ÉDUCATION NATIONALE ET NOUVELLE CITOYENNETÉ",
  schoolName: 'Complexe scolaire MALUNGA',
  province: 'Province Éducationnelle',
  city: '',
  commune: '',
  schoolCode: '',
} as const
