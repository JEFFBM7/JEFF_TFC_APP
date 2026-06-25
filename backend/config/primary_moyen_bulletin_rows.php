<?php

/**
 * Bulletin officiel primaire moyen (3ème et 4ème années) — IGE/P.S./002.
 * Trois trimestres, deux périodes par trimestre. Total annuel : 3600 pts.
 */
return [
    'form_code' => 'IGE/P.S./002',

    'legacy_aliases' => [
        'Langues nationales' => ['langues congolaises', 'langue congolaise', 'lecture ecriture', 'langues nationales'],
        'Français' => ['vocabulaire', 'expression orale', 'recitation', 'grammaire', 'conjugaison', 'orthographe', 'redaction', 'analyse', 'francais'],
        'Mathématiques' => ['numeration', 'operations', 'mesures', 'grandeur', 'formes geometriques', 'problemes', 'mathematiques'],
        'Sciences naturelles' => ['zoologie', 'botanique', 'eveil scientifique', 'sciences naturelles'],
        'Technologie' => ['technologie'],
        'Éducation civique et morale' => ['civique', 'morale', 'education civique'],
        'Éveil scientifique' => ['sante', 'environnement', 'education sante'],
        'Géographie' => ['geographie'],
        'Histoire' => ['histoire'],
        'Arts plastiques' => ['arts plastiques'],
        'Musique' => ['arts dramatiques', 'musique'],
        'Éducation physique' => ['education physique', 'eps', 'sport'],
    ],

    'rows' => [
        ['kind' => 'domain', 'label' => 'DOMAINE DES LANGUES', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subdomain', 'label' => 'LANGUES CONGOLAISES', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => 'Exp. Orale & Vocabulaire', 'alias_key' => 'Langues nationales', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Grammaire & Conjug.', 'alias_key' => 'Langues nationales', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Orth. & Rédaction', 'alias_key' => 'Langues nationales', 'trimester_max' => 20, 'period_max' => 5, 'exam_max' => 10],
        ['kind' => 'subtotal', 'label' => 'Sous-Total', 'trimester_max' => 100, 'period_max' => 25, 'exam_max' => 50],
        ['kind' => 'subdomain', 'label' => 'FRANÇAIS', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => 'Expr. orale - Récit. - Voc.', 'alias_key' => 'Français', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Orth. phras. Ecrit. & réd.', 'alias_key' => 'Français', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Gram. - Conj. - Analyse', 'alias_key' => 'Français', 'trimester_max' => 60, 'period_max' => 15, 'exam_max' => 30],
        ['kind' => 'subtotal', 'label' => 'Sous-Total', 'trimester_max' => 140, 'period_max' => 35, 'exam_max' => 70],
        ['kind' => 'subject', 'label' => 'Lect-Ecrit en langues congolaises', 'alias_key' => 'Langues nationales', 'trimester_max' => 120, 'period_max' => 30, 'exam_max' => 60],
        ['kind' => 'subject', 'label' => 'Lect-Ecrit en langue française', 'alias_key' => 'Français', 'trimester_max' => 120, 'period_max' => 30, 'exam_max' => 60],

        ['kind' => 'domain', 'label' => 'DOMAINE DES MATHEMATIQUES, SCIENCES ET TECHNOLOGIE', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subdomain', 'label' => 'MATHEMATIQUES', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => 'Numération', 'alias_key' => 'Mathématiques', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Opérations', 'alias_key' => 'Mathématiques', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Mesures des Grandeurs', 'alias_key' => 'Mathématiques', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Formes Géométriques', 'alias_key' => 'Mathématiques', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Problèmes', 'alias_key' => 'Mathématiques', 'trimester_max' => 80, 'period_max' => 20, 'exam_max' => 40],
        ['kind' => 'subdomain', 'label' => 'SCIENCES', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => 'Zoologie - botanique & Info.', 'alias_key' => 'Sciences naturelles', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subdomain', 'label' => 'TECHNOLOGIE', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => 'Technologie', 'alias_key' => 'Technologie', 'trimester_max' => 80, 'period_max' => 20, 'exam_max' => 40],
        ['kind' => 'subtotal', 'label' => 'Sous-Total', 'trimester_max' => 360, 'period_max' => 90, 'exam_max' => 180],

        ['kind' => 'domain', 'label' => "DOMAINE DE L'UNIVERS SOCIAL ET ENVIRONNEMENT", 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => 'Education civ. & morale', 'alias_key' => 'Éducation civique et morale', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Education santé & env.', 'alias_key' => 'Éveil scientifique', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Géographie', 'alias_key' => 'Géographie', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Histoire', 'alias_key' => 'Histoire', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subtotal', 'label' => 'Sous-Total', 'trimester_max' => 160, 'period_max' => 40, 'exam_max' => 80],

        ['kind' => 'domain', 'label' => 'DOMAINE DES ARTS', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => 'Arts plastiques', 'alias_key' => 'Arts plastiques', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Arts dramatiques', 'alias_key' => 'Musique', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subtotal', 'label' => 'Sous-Total', 'trimester_max' => 80, 'period_max' => 20, 'exam_max' => 40],

        ['kind' => 'domain', 'label' => 'DOMAINE DU DEVELOPPEMENT PERSONNEL', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => 'Ed. phys. & sportive', 'alias_key' => 'Éducation physique', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Init. Trav. Prod.', 'aliases' => ['travaux productifs', 'initiation'], 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Religion', 'aliases' => ['religion'], 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subtotal', 'label' => 'Sous-Total', 'trimester_max' => 120, 'period_max' => 30, 'exam_max' => 60],
    ],
];
