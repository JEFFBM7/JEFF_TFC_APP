<?php

/**
 * Bulletin officiel primaire début (1ère et 2ème années) — IGE/P.S./001.
 * Trois trimestres, deux périodes par trimestre.
 */
return [
    'form_code' => 'IGE/P.S./001',

    'legacy_aliases' => [
        'Langues nationales' => ['langues congolaises', 'langue congolaise', 'lecture labiale', 'lecture ecriture'],
        'Français' => ['vocabulaire', 'expression orale', 'art et parole', 'francais'],
        'Mathématiques' => ['mesure', 'formes geometriques', 'numeration', 'operations', 'problemes', 'mathematiques'],
        'Éveil scientifique' => ['sciences eveil', 'sciences d eveil'],
        'Arts plastiques' => ['arts plastiques', 'arts dramatiques'],
        'Musique' => ['arts dramatiques', 'art dramatique'],
        'Éducation physique' => ['education physique', 'eps', 'mobilite', 'sport'],
        'Éducation civique et morale' => ['civique', 'morale', 'education civique'],
    ],

    'rows' => [
        ['kind' => 'domain', 'label' => 'DOMAINE DES LANGUES', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subdomain', 'label' => 'LANGUES CONGOLAISES', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => 'Expression orale et langue des signes', 'alias_key' => 'Langues nationales', 'trimester_max' => 20, 'period_max' => 5, 'exam_max' => 10],
        ['kind' => 'subject', 'label' => 'Expression écrite et braille', 'alias_key' => 'Langues nationales', 'trimester_max' => 20, 'period_max' => 5, 'exam_max' => 10],
        ['kind' => 'subdomain', 'label' => 'FRANÇAIS', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => 'Vocabulaire', 'alias_key' => 'Français', 'trimester_max' => 20, 'period_max' => 5, 'exam_max' => 10],
        ['kind' => 'subject', 'label' => 'Expression orale / Art et parole', 'alias_key' => 'Français', 'trimester_max' => 20, 'period_max' => 5, 'exam_max' => 10],
        ['kind' => 'subdomain', 'label' => 'LECTURE - ECRITURE', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => 'Lecture et écriture en langue congolaise ou Lecture labiale', 'alias_key' => 'Langues nationales', 'trimester_max' => 20, 'period_max' => 5, 'exam_max' => 10],
        ['kind' => 'subtotal', 'label' => 'Sous-Total', 'trimester_max' => 100, 'period_max' => 25, 'exam_max' => 50],

        ['kind' => 'domain', 'label' => 'DOMAINE DES MATHEMATIQUES, SCIENCES ET TECHNOLOGIE', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subdomain', 'label' => 'MATHEMATIQUES', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => 'Mesure', 'alias_key' => 'Mathématiques', 'trimester_max' => 20, 'period_max' => 5, 'exam_max' => 10],
        ['kind' => 'subject', 'label' => 'Formes géométriques', 'alias_key' => 'Mathématiques', 'trimester_max' => 20, 'period_max' => 5, 'exam_max' => 10],
        ['kind' => 'subject', 'label' => 'Numération', 'alias_key' => 'Mathématiques', 'trimester_max' => 20, 'period_max' => 5, 'exam_max' => 10],
        ['kind' => 'subject', 'label' => 'Opérations', 'alias_key' => 'Mathématiques', 'trimester_max' => 20, 'period_max' => 5, 'exam_max' => 10],
        ['kind' => 'subject', 'label' => 'Problèmes', 'alias_key' => 'Mathématiques', 'trimester_max' => 20, 'period_max' => 5, 'exam_max' => 10],
        ['kind' => 'subdomain', 'label' => 'SCIENCES', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => "Sciences d'éveil", 'alias_key' => 'Éveil scientifique', 'trimester_max' => 20, 'period_max' => 5, 'exam_max' => 10],
        ['kind' => 'subject', 'label' => 'Technologie', 'aliases' => ['technologie'], 'trimester_max' => 20, 'period_max' => 5, 'exam_max' => 10],
        ['kind' => 'subtotal', 'label' => 'Sous-Total', 'trimester_max' => 140, 'period_max' => 35, 'exam_max' => 70],

        ['kind' => 'domain', 'label' => "DOMAINE DE L'UNIVERS SOCIAL ET ENVIRONNEMENT", 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => 'Education civique et morale', 'alias_key' => 'Éducation civique et morale', 'trimester_max' => 20, 'period_max' => 5, 'exam_max' => 10],
        ['kind' => 'subject', 'label' => 'Education à la santé et à l\'environnement', 'aliases' => ['sante', 'environnement', 'eveil scientifique'], 'trimester_max' => 20, 'period_max' => 5, 'exam_max' => 10],
        ['kind' => 'subtotal', 'label' => 'Sous-Total', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],

        ['kind' => 'domain', 'label' => 'DOMAINE DES ARTS', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => 'Arts plastiques', 'alias_key' => 'Arts plastiques', 'trimester_max' => 20, 'period_max' => 5, 'exam_max' => 10],
        ['kind' => 'subject', 'label' => 'Arts dramatiques', 'alias_key' => 'Musique', 'trimester_max' => 20, 'period_max' => 5, 'exam_max' => 10],
        ['kind' => 'subtotal', 'label' => 'Sous-Total', 'trimester_max' => 40, 'period_max' => 10, 'exam_max' => 20],

        ['kind' => 'domain', 'label' => 'DOMAINE DU DEVELOPPEMENT PERSONNEL', 'trimester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
        ['kind' => 'subject', 'label' => 'Education physique et sport/mobilité', 'alias_key' => 'Éducation physique', 'trimester_max' => 20, 'period_max' => 5, 'exam_max' => 10],
        ['kind' => 'subject', 'label' => 'Initiation aux travaux productifs', 'aliases' => ['travaux productifs', 'initiation'], 'trimester_max' => 20, 'period_max' => 5, 'exam_max' => 10],
        ['kind' => 'subject', 'label' => 'Religion', 'aliases' => ['religion'], 'trimester_max' => 20, 'period_max' => 5, 'exam_max' => 10],
        ['kind' => 'subtotal', 'label' => 'Sous-Total', 'trimester_max' => 60, 'period_max' => 15, 'exam_max' => 30],
    ],
];
