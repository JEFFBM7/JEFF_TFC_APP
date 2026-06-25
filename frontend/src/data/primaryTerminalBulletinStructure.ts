/**
 * Bulletin officiel primaire degré terminal (5ème et 6ème années) — IGE/P.S/006.
 * Trois trimestres, deux périodes par trimestre. Total annuel : 3720 pts.
 */

import type { PrimaryLevelRef, PrimaryRowKind, PrimarySubjectRow } from './primaryBulletinStructure'

export type PrimaryTerminalGradeYear = 5 | 6

export interface PrimaryTerminalBulletinMeta {
  gradeYear: PrimaryTerminalGradeYear
  title: string
}

const TERMINAL_ALIASES = {
  languesNationales: ['langues congolaises', 'langue congolaise', 'lecture ecriture', 'langues nationales'],
  francais: ['vocabulaire', 'expression orale', 'redaction', 'grammaire', 'conjugaison', 'orthographe', 'analyse', 'francais'],
  mathematiques: ['numeration', 'operations', 'mesures', 'grandeur', 'formes geometriques', 'problemes', 'mathematiques'],
  sciences: ['physique', 'zoologie', 'information', 'anatomie', 'botanique', 'sciences naturelles', 'eveil scientifique'],
  technologie: ['technologie'],
  civique: ['civique', 'morale', 'education civique', 'education civique et morale'],
  sante: ['sante', 'environnement', 'education sante'],
  geographie: ['geographie'],
  histoire: ['histoire'],
  artsPlastiques: ['arts plastiques'],
  musique: ['arts dramatiques', 'musique'],
  eps: ['education physique', 'eps', 'sport'],
} as const

export const PRIMARY_TERMINAL_BULLETIN_ROWS: PrimarySubjectRow[] = [
  { kind: 'domain' as PrimaryRowKind, label: 'DOMAINE DES LANGUES', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subdomain', label: 'LANGUES CONGOLAISES', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Gram. & Conj.', aliases: [...TERMINAL_ALIASES.languesNationales], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Expr. Orale & Vocab.', aliases: [...TERMINAL_ALIASES.languesNationales], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Orth. & rédaction', aliases: [...TERMINAL_ALIASES.languesNationales], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subtotal', label: 'Sous-total', trimesterMax: 120, periodMax: 30, examMax: 60 },
  { kind: 'subdomain', label: 'FRANÇAIS', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Expr. Oral & Vocab.', aliases: [...TERMINAL_ALIASES.francais], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Orthographe', aliases: [...TERMINAL_ALIASES.francais], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Rédaction', aliases: [...TERMINAL_ALIASES.francais], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Gram. Conj. Analyse', aliases: [...TERMINAL_ALIASES.francais], trimesterMax: 80, periodMax: 20, examMax: 40 },
  { kind: 'subtotal', label: 'Sous-total', trimesterMax: 200, periodMax: 50, examMax: 100 },
  { kind: 'subject', label: 'Lect.- écriture en langues congolaises', aliases: [...TERMINAL_ALIASES.languesNationales], trimesterMax: 80, periodMax: 20, examMax: 40 },
  { kind: 'subject', label: 'Lect. - écriture en langue française', aliases: [...TERMINAL_ALIASES.francais], trimesterMax: 80, periodMax: 20, examMax: 40 },

  { kind: 'domain', label: 'DOMAINE DES MATHEMATIQUES, SCIENCES ET TECHNOLOGIE', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subdomain', label: 'MATHEMATIQUE', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Numération', aliases: [...TERMINAL_ALIASES.mathematiques], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Opérations', aliases: [...TERMINAL_ALIASES.mathematiques], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Mesures des grandeurs', aliases: [...TERMINAL_ALIASES.mathematiques], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Formes géométriques', aliases: [...TERMINAL_ALIASES.mathematiques], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Problèmes', aliases: [...TERMINAL_ALIASES.mathematiques], trimesterMax: 80, periodMax: 20, examMax: 40 },
  { kind: 'subtotal', label: 'Sous-total', trimesterMax: 240, periodMax: 60, examMax: 120 },
  { kind: 'subdomain', label: 'SCIENCES', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Phys.- zoolo. - Info.', aliases: [...TERMINAL_ALIASES.sciences], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Anatomie -botanique', aliases: [...TERMINAL_ALIASES.sciences], trimesterMax: 80, periodMax: 20, examMax: 40 },
  { kind: 'subtotal', label: 'Sous-total', trimesterMax: 120, periodMax: 30, examMax: 60 },
  { kind: 'subdomain', label: 'TECHNOLOGIE', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Technologie', aliases: [...TERMINAL_ALIASES.technologie], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subtotal', label: 'Sous-total', trimesterMax: 40, periodMax: 10, examMax: 20 },

  { kind: 'domain', label: "DOMAINE DE L'UNIVERS SOCIAL ET ENVIRONNEMENT", trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Ed. civ & morale', aliases: [...TERMINAL_ALIASES.civique], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Ed. santé & env.', aliases: [...TERMINAL_ALIASES.sante], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Géographie', aliases: [...TERMINAL_ALIASES.geographie], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Histoire', aliases: [...TERMINAL_ALIASES.histoire], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subtotal', label: 'Sous-total', trimesterMax: 160, periodMax: 40, examMax: 80 },

  { kind: 'domain', label: 'DOMAINE DES ARTS', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subdomain', label: 'EDUCATION ARTISTIQUE', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Arts Plastiques', aliases: [...TERMINAL_ALIASES.artsPlastiques], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Arts Dramatiques', aliases: [...TERMINAL_ALIASES.musique], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subtotal', label: 'Sous-total', trimesterMax: 80, periodMax: 20, examMax: 40 },

  { kind: 'domain', label: 'DOMAINE DU DEVELOPPEMENT PERSONNEL', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subdomain', label: 'EDUCATION PHYSIQUE', trimesterMax: 0, periodMax: 0, examMax: 0 },
  { kind: 'subject', label: 'Init. Trav. Prod.', aliases: ['travaux productifs', 'initiation'], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Ed. phys. & sportive', aliases: [...TERMINAL_ALIASES.eps], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subject', label: 'Religion', aliases: ['religion'], trimesterMax: 40, periodMax: 10, examMax: 20 },
  { kind: 'subtotal', label: 'Sous-total', trimesterMax: 120, periodMax: 30, examMax: 60 },
]

export function resolvePrimaryTerminalGradeYear(level?: PrimaryLevelRef | null): PrimaryTerminalGradeYear {
  const abbreviation = (level?.abbreviation ?? '').toUpperCase()
  if (abbreviation === '6P' || abbreviation.startsWith('6')) return 6
  return 5
}

export function primaryTerminalBulletinMeta(level?: PrimaryLevelRef | null): PrimaryTerminalBulletinMeta {
  const gradeYear = resolvePrimaryTerminalGradeYear(level)
  return {
    gradeYear,
    title: `BULLETIN DE L'ÉLÈVE DEGRÉ TERMINAL (${gradeYear}e ANNÉE)`,
  }
}

export function getPrimaryTerminalBulletinRows(): PrimarySubjectRow[] {
  return PRIMARY_TERMINAL_BULLETIN_ROWS
}

export const PRIMARY_TERMINAL_GRAND_TOTAL_MAX = 3720
export const PRIMARY_TERMINAL_FORM_CODE = 'IGE/P.S/006'
