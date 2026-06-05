<?php

/**
 * Grilles officielles des maxima CTEB par année (7e et 8e distinctes).
 *
 * period_max = max par période (1ère P / 2ème P ou 3ème P / 4ème P)
 * exam_max   = max examen de semestre
 * semester_max = total semestre (journal + examen)
 */
return [
    'legacy_aliases' => [
        'Mathématiques' => ['arithmetique', 'statistique', 'geometrie', 'algebre', 'mathematiques'],
        'Biologie' => ['anatomie', 'botanique', 'nutrition', 'zoologie'],
        'Physique' => ['sciences physiques'],
        'Informatique' => ['tic', 'informatique'],
        'Éducation civique et morale' => ['civique', 'morale', 'ed civique'],
        'Éducation à la Vie' => ['education a la vie', 'ed a la vie'],
        'Éducation physique' => ['eps', 'sport', 'ed physique'],
        'Arts plastiques' => ['dessin'],
    ],

    'rows_by_grade' => [
        7 => [
            ['kind' => 'domain', 'label' => 'DOMAINE DES SCIENCES', 'semester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
            ['kind' => 'subdomain', 'label' => 'Sous-domaine des Mathématiques', 'semester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
            ['kind' => 'subject', 'label' => 'Arithmétique', 'alias_key' => 'Mathématiques', 'semester_max' => 40, 'period_max' => 5, 'exam_max' => 20],
            ['kind' => 'subject', 'label' => 'Statistique', 'aliases' => ['statistiques'], 'semester_max' => 40, 'period_max' => 5, 'exam_max' => 20],
            ['kind' => 'subject', 'label' => 'Géométrie', 'aliases' => ['geometrie'], 'semester_max' => 80, 'period_max' => 10, 'exam_max' => 40],
            ['kind' => 'subject', 'label' => 'Algèbre', 'aliases' => ['algebre'], 'semester_max' => 160, 'period_max' => 20, 'exam_max' => 80],
            ['kind' => 'subtotal', 'label' => 'Sous-Total', 'semester_max' => 320, 'period_max' => 40, 'exam_max' => 160],

            ['kind' => 'subdomain', 'label' => 'Sous-domaine des Sciences de la Vie et de la Terre', 'semester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
            ['kind' => 'subject', 'label' => 'Anatomie', 'alias_key' => 'Biologie', 'semester_max' => 40, 'period_max' => 5, 'exam_max' => 20],
            ['kind' => 'subject', 'label' => 'Botanique', 'semester_max' => 40, 'period_max' => 5, 'exam_max' => 20],
            ['kind' => 'subject', 'label' => 'Zoologie', 'semester_max' => 40, 'period_max' => 5, 'exam_max' => 20],
            ['kind' => 'subtotal', 'label' => 'Sous-Total', 'semester_max' => 120, 'period_max' => 15, 'exam_max' => 60],

            ['kind' => 'subdomain', 'label' => 'Sous-domaine des Sciences Physiques, Technologie et TIC', 'semester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
            ['kind' => 'subject', 'label' => 'Sciences Physiques', 'alias_key' => 'Physique', 'extra_aliases' => ['physique'], 'semester_max' => 40, 'period_max' => 5, 'exam_max' => 20],
            ['kind' => 'subject', 'label' => 'Technologie', 'semester_max' => 40, 'period_max' => 5, 'exam_max' => 20],
            ['kind' => 'subject', 'label' => "Techn. d'Info. & Com (TIC)", 'alias_key' => 'Informatique', 'semester_max' => 40, 'period_max' => 5, 'exam_max' => 20],
            ['kind' => 'subtotal', 'label' => 'Sous-Total', 'semester_max' => 120, 'period_max' => 15, 'exam_max' => 60],

            ['kind' => 'domain', 'label' => 'DOMAINE DES LANGUES', 'semester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
            ['kind' => 'subject', 'label' => 'Anglais', 'aliases' => ['english'], 'semester_max' => 120, 'period_max' => 15, 'exam_max' => 60],
            ['kind' => 'subject', 'label' => 'Français', 'aliases' => ['francais'], 'semester_max' => 280, 'period_max' => 35, 'exam_max' => 140],
            ['kind' => 'subtotal', 'label' => 'Sous-Total', 'semester_max' => 400, 'period_max' => 50, 'exam_max' => 200],

            ['kind' => 'domain', 'label' => "DOMAINE DE L'UNIVERS SOCIAL ET ENVIRONNEMENT", 'semester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
            ['kind' => 'subject', 'label' => 'Religion', 'semester_max' => 80, 'period_max' => 10, 'exam_max' => 40],
            ['kind' => 'subject', 'label' => 'Education à la vie', 'alias_key' => 'Éducation à la Vie', 'semester_max' => 80, 'period_max' => 10, 'exam_max' => 40],
            ['kind' => 'subject', 'label' => 'Ed. civique et morale', 'alias_key' => 'Éducation civique et morale', 'semester_max' => 80, 'period_max' => 10, 'exam_max' => 40],
            ['kind' => 'subject', 'label' => 'Histoire', 'semester_max' => 80, 'period_max' => 10, 'exam_max' => 40],
            ['kind' => 'subject', 'label' => 'Géographie', 'aliases' => ['geographie'], 'semester_max' => 80, 'period_max' => 10, 'exam_max' => 40],
            ['kind' => 'subtotal', 'label' => 'Sous-Total', 'semester_max' => 400, 'period_max' => 50, 'exam_max' => 200],

            ['kind' => 'domain', 'label' => 'DOMAINE DES ARTS', 'semester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
            ['kind' => 'subject', 'label' => 'Dessin', 'alias_key' => 'Arts plastiques', 'semester_max' => 80, 'period_max' => 10, 'exam_max' => 40],
            ['kind' => 'subject', 'label' => 'Musique', 'semester_max' => 80, 'period_max' => 10, 'exam_max' => 40],
            ['kind' => 'subtotal', 'label' => 'Sous-Total', 'semester_max' => 160, 'period_max' => 20, 'exam_max' => 80],

            ['kind' => 'domain', 'label' => 'DOMAINE DU DÉVELOPPEMENT PERSONNEL', 'semester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
            ['kind' => 'subject', 'label' => 'Ed. Physique & Sportive', 'alias_key' => 'Éducation physique', 'semester_max' => 80, 'period_max' => 10, 'exam_max' => 40],
            ['kind' => 'subtotal', 'label' => 'Sous-Total', 'semester_max' => 80, 'period_max' => 10, 'exam_max' => 40],
        ],

        8 => [
            ['kind' => 'domain', 'label' => 'DOMAINE DES SCIENCES', 'semester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
            ['kind' => 'subdomain', 'label' => 'Sous-domaine des Mathématiques', 'semester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
            ['kind' => 'subject', 'label' => 'Arithmétique', 'alias_key' => 'Mathématiques', 'semester_max' => 40, 'period_max' => 5, 'exam_max' => 20],
            ['kind' => 'subject', 'label' => 'Statistique', 'aliases' => ['statistiques'], 'semester_max' => 40, 'period_max' => 5, 'exam_max' => 20],
            ['kind' => 'subject', 'label' => 'Géométrie', 'aliases' => ['geometrie'], 'semester_max' => 80, 'period_max' => 10, 'exam_max' => 40],
            ['kind' => 'subject', 'label' => 'Algèbre', 'aliases' => ['algebre'], 'semester_max' => 160, 'period_max' => 20, 'exam_max' => 80],
            ['kind' => 'subtotal', 'label' => 'Sous-Total', 'semester_max' => 320, 'period_max' => 40, 'exam_max' => 160],

            ['kind' => 'subdomain', 'label' => 'Sous-domaine des Sciences de la Vie et de la Terre', 'semester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
            ['kind' => 'subject', 'label' => 'Anatomie', 'alias_key' => 'Biologie', 'semester_max' => 40, 'period_max' => 5, 'exam_max' => 20],
            ['kind' => 'subject', 'label' => 'Botanique', 'semester_max' => 40, 'period_max' => 5, 'exam_max' => 20],
            ['kind' => 'subject', 'label' => 'Nutrition', 'semester_max' => 40, 'period_max' => 5, 'exam_max' => 20],
            ['kind' => 'subject', 'label' => 'Zoologie', 'semester_max' => 80, 'period_max' => 10, 'exam_max' => 40],
            ['kind' => 'subtotal', 'label' => 'Sous-Total', 'semester_max' => 200, 'period_max' => 25, 'exam_max' => 100],

            ['kind' => 'subdomain', 'label' => 'Sous-domaine des Sciences Physiques, Technologie et TIC', 'semester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
            ['kind' => 'subject', 'label' => 'Chimie', 'semester_max' => 40, 'period_max' => 5, 'exam_max' => 20],
            ['kind' => 'subject', 'label' => 'Sciences Physiques', 'alias_key' => 'Physique', 'extra_aliases' => ['physique'], 'semester_max' => 40, 'period_max' => 5, 'exam_max' => 20],
            ['kind' => 'subject', 'label' => 'Technologie', 'semester_max' => 40, 'period_max' => 5, 'exam_max' => 20],
            ['kind' => 'subject', 'label' => "Techn. d'Info. & Com (TIC)", 'alias_key' => 'Informatique', 'semester_max' => 40, 'period_max' => 5, 'exam_max' => 20],
            ['kind' => 'subtotal', 'label' => 'Sous-Total', 'semester_max' => 160, 'period_max' => 20, 'exam_max' => 80],

            ['kind' => 'domain', 'label' => 'DOMAINE DES LANGUES', 'semester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
            ['kind' => 'subject', 'label' => 'Anglais', 'aliases' => ['english'], 'semester_max' => 120, 'period_max' => 15, 'exam_max' => 60],
            ['kind' => 'subject', 'label' => 'Français', 'aliases' => ['francais'], 'semester_max' => 200, 'period_max' => 25, 'exam_max' => 100],
            ['kind' => 'subtotal', 'label' => 'Sous-Total', 'semester_max' => 320, 'period_max' => 40, 'exam_max' => 160],

            ['kind' => 'domain', 'label' => "DOMAINE DE L'UNIVERS SOCIAL ET ENVIRONNEMENT", 'semester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
            ['kind' => 'subject', 'label' => 'Religion', 'semester_max' => 80, 'period_max' => 10, 'exam_max' => 40],
            ['kind' => 'subject', 'label' => 'Education à la vie', 'alias_key' => 'Éducation à la Vie', 'semester_max' => 80, 'period_max' => 10, 'exam_max' => 40],
            ['kind' => 'subject', 'label' => 'Ed. civique et morale', 'alias_key' => 'Éducation civique et morale', 'semester_max' => 80, 'period_max' => 10, 'exam_max' => 40],
            ['kind' => 'subject', 'label' => 'Histoire', 'semester_max' => 80, 'period_max' => 10, 'exam_max' => 40],
            ['kind' => 'subject', 'label' => 'Géographie', 'aliases' => ['geographie'], 'semester_max' => 120, 'period_max' => 15, 'exam_max' => 60],
            ['kind' => 'subtotal', 'label' => 'Sous-Total', 'semester_max' => 440, 'period_max' => 55, 'exam_max' => 220],

            ['kind' => 'domain', 'label' => 'DOMAINE DES ARTS', 'semester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
            ['kind' => 'subject', 'label' => 'Dessin', 'alias_key' => 'Arts plastiques', 'semester_max' => 80, 'period_max' => 10, 'exam_max' => 40],
            ['kind' => 'subject', 'label' => 'Musique', 'semester_max' => 80, 'period_max' => 10, 'exam_max' => 40],
            ['kind' => 'subtotal', 'label' => 'Sous-Total', 'semester_max' => 160, 'period_max' => 20, 'exam_max' => 80],

            ['kind' => 'domain', 'label' => 'DOMAINE DU DÉVELOPPEMENT PERSONNEL', 'semester_max' => 0, 'period_max' => 0, 'exam_max' => 0],
            ['kind' => 'subject', 'label' => 'Ed. Physique & Sportive', 'alias_key' => 'Éducation physique', 'semester_max' => 80, 'period_max' => 10, 'exam_max' => 40],
            ['kind' => 'subtotal', 'label' => 'Sous-Total', 'semester_max' => 80, 'period_max' => 10, 'exam_max' => 40],
        ],
    ],
];
