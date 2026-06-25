<?php

/**
 * Bulletin officiel primaire degré terminal (5ème et 6ème années) — IGE/P.S/006.
 * Trois trimestres, deux périodes par trimestre. Total annuel : 3720 pts.
 */
return [
    'form_code' => 'IGE/P.S/006',

    'legacy_aliases' => [
        'Langues nationales' => ['langues congolaises', 'langue congolaise', 'lecture ecriture', 'langues nationales'],
        'Français' => ['vocabulaire', 'expression orale', 'redaction', 'grammaire', 'conjugaison', 'orthographe', 'analyse', 'francais'],
        'Mathématiques' => ['numeration', 'operations', 'mesures', 'grandeur', 'formes geometriques', 'problemes', 'mathematiques'],
        'Sciences naturelles' => ['physique', 'zoologie', 'information', 'anatomie', 'botanique', 'sciences naturelles', 'eveil scientifique'],
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
        ['kind' => 'subject', 'label' => 'Gram. & Conj.', 'alias_key' => 'Langues nationales', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Expr. Orale & Vocab.', 'alias_key' => 'Langues nationales', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Orth. & rédaction', 'alias_key' => 'Langues nationales', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subtotal', 'label' => 'Sous-total', 'trimester_max' => 120, 'period_max' => 30, 'exam_max' => 60],
        ['kind' => 'subdomain', 'label' => 'FRANÇAIS', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => 'Expr. Oral & Vocab.', 'alias_key' => 'Français', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Orthographe', 'alias_key' => 'Français', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Rédaction', 'alias_key' => 'Français', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Gram. Conj. Analyse', 'alias_key' => 'Français', 'trimester_max' => 80, 'period_max' => 20, 'exam_max' => 40],
        ['kind' => 'subtotal', 'label' => 'Sous-total', 'trimester_max' => 200, 'period_max' => 50, 'exam_max' => 100],
        ['kind' => 'subject', 'label' => 'Lect.- écriture en langues congolaises', 'alias_key' => 'Langues nationales', 'trimester_max' => 80, 'period_max' => 20, 'exam_max' => 40],
        ['kind' => 'subject', 'label' => 'Lect. - écriture en langue française', 'alias_key' => 'Français', 'trimester_max' => 80, 'period_max' => 20, 'exam_max' => 40],

        ['kind' => 'domain', 'label' => 'DOMAINE DES MATHEMATIQUES, SCIENCES ET TECHNOLOGIE', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subdomain', 'label' => 'MATHEMATIQUE', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => 'Numération', 'alias_key' => 'Mathématiques', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Opérations', 'alias_key' => 'Mathématiques', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Mesures des grandeurs', 'alias_key' => 'Mathématiques', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Formes géométriques', 'alias_key' => 'Mathématiques', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Problèmes', 'alias_key' => 'Mathématiques', 'trimester_max' => 80, 'period_max' => 20, 'exam_max' => 40],
        ['kind' => 'subtotal', 'label' => 'Sous-total', 'trimester_max' => 240, 'period_max' => 60, 'exam_max' => 120],
        ['kind' => 'subdomain', 'label' => 'SCIENCES', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => 'Phys.- zoolo. - Info.', 'alias_key' => 'Sciences naturelles', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Anatomie -botanique', 'alias_key' => 'Sciences naturelles', 'trimester_max' => 80, 'period_max' => 20, 'exam_max' => 40],
        ['kind' => 'subtotal', 'label' => 'Sous-total', 'trimester_max' => 120, 'period_max' => 30, 'exam_max' => 60],
        ['kind' => 'subdomain', 'label' => 'TECHNOLOGIE', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => 'Technologie', 'alias_key' => 'Technologie', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subtotal', 'label' => 'Sous-total', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],

        ['kind' => 'domain', 'label' => "DOMAINE DE L'UNIVERS SOCIAL ET ENVIRONNEMENT", 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => 'Ed. civ & morale', 'alias_key' => 'Éducation civique et morale', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Ed. santé & env.', 'alias_key' => 'Éveil scientifique', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Géographie', 'alias_key' => 'Géographie', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Histoire', 'alias_key' => 'Histoire', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subtotal', 'label' => 'Sous-total', 'trimester_max' => 160, 'period_max' => 40, 'exam_max' => 80],

        ['kind' => 'domain', 'label' => 'DOMAINE DES ARTS', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subdomain', 'label' => 'EDUCATION ARTISTIQUE', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => 'Arts Plastiques', 'alias_key' => 'Arts plastiques', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Arts Dramatiques', 'alias_key' => 'Musique', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subtotal', 'label' => 'Sous-total', 'trimester_max' => 80, 'period_max' => 20, 'exam_max' => 40],

        ['kind' => 'domain', 'label' => 'DOMAINE DU DEVELOPPEMENT PERSONNEL', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subdomain', 'label' => 'EDUCATION PHYSIQUE', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => 'Init. Trav. Prod.', 'aliases' => ['travaux productifs', 'initiation'], 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Ed. phys. & sportive', 'alias_key' => 'Éducation physique', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subject', 'label' => 'Religion', 'aliases' => ['religion'], 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],
        ['kind' => 'subtotal', 'label' => 'Sous-total', 'trimester_max' => 120, 'period_max' => 30, 'exam_max' => 60],
    ],
];
