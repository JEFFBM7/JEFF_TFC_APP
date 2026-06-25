/**
 * Bulletin officiel primaire moyen (3ème et 4ème années) — IGE/P.S./002.
 * Trois trimestres, deux périodes par trimestre. Total annuel : 3600 pts.
 */

import type { PrimaryLevelRef, PrimaryRowKind, PrimarySubjectRow } from './primaryBulletinStructure'

export type PrimaryMoyenGradeYear = 3 | 4

export interface PrimaryMoyenBulletinMeta {
  gradeYear: PrimaryMoyenGradeYear
  title: string
}

const MOYEN_ALIASES = {
  languesNationales: ['langues congolaises', 'langue congolaise', 'lecture ecriture', 'langues nationales'],
  francais: ['vocabulaire', 'expression orale', 'recitation', 'grammaire', 'conjugaison', 'orthographe', 'redaction', 'analyse', 'francais'],
  mathematiques: ['numeration', 'operations', 'mesures', 'grandeur', 'formes geometriques', 'problemes', 'mathematiques'],
  sciences: ['zoologie', 'botanique', 'eveil scientifique', 'sciences naturelles'],
  technologie: ['technologie'],
  civique: ['civique', 'morale', 'education civique', 'education civique et morale'],
  sante: ['sante', 'environnement', 'eveil scientifique'],
  geographie: ['geographie'],
  histoire: ['histoire'],
  artsPlastiques: ['arts plastiques'],
  musique: ['arts dramatiques', 'musique'],
  eps: ['education physique', 'eps', 'sport'],
} as const

export const PRIMARY_MOYEN_BULLETIN_ROWS: PrimarySubjectRow[] = [
  { kind: 'domain' as PrimaryRowKind, label: 'DOMAINE DES LANGUES', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subdomain', label: 'LANGUES CONGOLAISES', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Exp. Orale & Vocabulaire', aliases: [...MOYEN_ALIASES.languesNationales], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Grammaire & Conjug.', aliases: [...MOYEN_ALIASES.languesNationales], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Orth. & Rédaction', aliases: [...MOYEN_ALIASES.languesNationales], trimesterMax: 20, periodMax: 5, examMax: 10 },
  { kind: 'subtotal', label: 'Sous-Total', trimesterMax: 100, periodMax: 25, examMax: 50 },
  { kind: 'subdomain', label: 'FRANÇAIS', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Expr. orale - Récit. - Voc.', aliases: [...MOYEN_ALIASES.francais], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Orth. phras. Ecrit. & réd.', aliases: [...MOYEN_ALIASES.francais], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Gram. - Conj. - Analyse', aliases: [...MOYEN_ALIASES.francais], trimesterMax: 60, periodMax: 15, examMax: 30 },
  { kind: 'subtotal', label: 'Sous-Total', trimesterMax: 140, periodMax: 35, examMax: 70 },
  { kind: 'subject', label: 'Lect-Ecrit en langues congolaises', aliases: [...MOYEN_ALIASES.languesNationales], trimesterMax: 120, periodMax: 30, examMax: 60 },
  { kind: 'subject', label: 'Lect-Ecrit en langue française', aliases: [...MOYEN_ALIASES.francais], trimesterMax: 120, periodMax: 30, examMax: 60 },

  { kind: 'domain', label: 'DOMAINE DES MATHEMATIQUES, SCIENCES ET TECHNOLOGIE', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subdomain', label: 'MATHEMATIQUES', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Numération', aliases: [...MOYEN_ALIASES.mathematiques], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Opérations', aliases: [...MOYEN_ALIASES.mathematiques], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Mesures des Grandeurs', aliases: [...MOYEN_ALIASES.mathematiques], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Formes Géométriques', aliases: [...MOYEN_ALIASES.mathematiques], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Problèmes', aliases: [...MOYEN_ALIASES.mathematiques], trimesterMax: 80, periodMax: 20, examMax: 40 },
  { kind: 'subdomain', label: 'SCIENCES', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Zoologie - botanique & Info.', aliases: [...MOYEN_ALIASES.sciences], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subdomain', label: 'TECHNOLOGIE', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Technologie', aliases: [...MOYEN_ALIASES.technologie], trimesterMax: 80, periodMax: 20, examMax: 40 },
  { kind: 'subtotal', label: 'Sous-Total', trimesterMax: 360, periodMax: 90, examMax: 180 },

  { kind: 'domain', label: "DOMAINE DE L'UNIVERS SOCIAL ET ENVIRONNEMENT", trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Education civ. & morale', aliases: [...MOYEN_ALIASES.civique], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Education santé & env.', aliases: [...MOYEN_ALIASES.sante], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Géographie', aliases: [...MOYEN_ALIASES.geographie], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Histoire', aliases: [...MOYEN_ALIASES.histoire], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subtotal', label: 'Sous-Total', trimesterMax: 160, periodMax: 40, examMax: 80 },

  { kind: 'domain', label: 'DOMAINE DES ARTS', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Arts plastiques', aliases: [...MOYEN_ALIASES.artsPlastiques], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Arts dramatiques', aliases: [...MOYEN_ALIASES.musique], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subtotal', label: 'Sous-Total', trimesterMax: 80, periodMax: 20, examMax: 40 },

  { kind: 'domain', label: 'DOMAINE DU DEVELOPPEMENT PERSONNEL', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Ed. phys. & sportive', aliases: [...MOYEN_ALIASES.eps], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Init. Trav. Prod.', aliases: ['travaux productifs', 'initiation'], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Religion', aliases: ['religion'], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subtotal', label: 'Sous-Total', trimesterMax: 120, periodMax: 30, examMax: 60 },
]

export function resolvePrimaryMoyenGradeYear(level?: PrimaryLevelRef | null): PrimaryMoyenGradeYear {
  const abbreviation = (level?.abbreviation ?? '').toUpperCase()
  if (abbreviation === '4P' || abbreviation.startsWith('4')) return 4
  return 3
}

export function isPrimaireMoyenLevel(level?: PrimaryLevelRef | null): boolean {
  if (level?.cycle !== 'primaire') return false
  const abbreviation = (level?.abbreviation ?? '').toUpperCase()
  return abbreviation === '3P' || abbreviation === '4P'
}

export function primaryMoyenBulletinMeta(level?: PrimaryLevelRef | null): PrimaryMoyenBulletinMeta {
  const gradeYear = resolvePrimaryMoyenGradeYear(level)
  const yearLabel = gradeYear === 3 ? '3ème' : '4ème'
  return {
    gradeYear,
    title: `BULLETIN DE L'ÉLÈVE : DEGRÉ MOYEN (${yearLabel} année)`,
  }
}

export function getPrimaryMoyenBulletinRows(): PrimarySubjectRow[] {
  return PRIMARY_MOYEN_BULLETIN_ROWS
}

export const PRIMARY_MOYEN_GRAND_TOTAL_MAX = 3600
export const PRIMARY_MOYEN_FORM_CODE = 'IGE/P.S./002'
