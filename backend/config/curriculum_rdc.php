<?php

/**
 * Programme scolaire officiel RDC (EPST) — catalogue et rattachements par niveau / option.
 *
 * Maternelle (M1–M3) : exclue en v1.
 */
$ctebSubjects = require __DIR__.'/cteb_subjects.php';

return [
    'excluded_abbreviations' => ['M1', 'M2', 'M3'],

    'level_groups' => [
        '1P' => 'primaire_debut',
        '2P' => 'primaire_debut',
        '3P' => 'primaire_debut',
        '4P' => 'primaire_fin',
        '5P' => 'primaire_fin',
        '6P' => 'primaire_fin',
        '7EB' => 'cteb',
        '8EB' => 'cteb',
        '9S' => 'secondaire',
        '10S' => 'secondaire',
        '11S' => 'secondaire',
        '12S' => 'secondaire',
    ],

    'philosophy_abbreviations' => ['12S'],

    /*
    |--------------------------------------------------------------------------
    | Catalogue global des matières
    |--------------------------------------------------------------------------
    */
    'catalog' => [
        'Français' => ['code' => 'FR', 'description' => 'Langue, grammaire, littérature', 'default_coefficient' => 4],
        'Mathématiques' => ['code' => 'MATH', 'description' => 'Algèbre, géométrie, statistiques', 'default_coefficient' => 4],
        'Langues nationales' => ['code' => 'LN', 'description' => 'Lingala, Kikongo, Tshiluba, Swahili', 'default_coefficient' => 2],
        'Éveil scientifique' => ['code' => 'EVS', 'description' => 'Sciences, histoire et géographie au primaire', 'default_coefficient' => 2],
        'Arts plastiques' => ['code' => 'ART', 'description' => 'Dessin, peinture, modelage', 'default_coefficient' => 1],
        'Musique' => ['code' => 'MUS', 'description' => 'Éducation musicale et chant', 'default_coefficient' => 1],
        'Éducation physique' => ['code' => 'EPS', 'description' => 'EPS', 'default_coefficient' => 1],
        'Sciences naturelles' => ['code' => 'SN', 'description' => 'Biologie et géologie (SVT)', 'default_coefficient' => 2],
        'Géographie' => ['code' => 'GEO', 'description' => 'Géographie physique et humaine', 'default_coefficient' => 2],
        'Histoire' => ['code' => 'HIST', 'description' => 'Histoire générale et de la RDC', 'default_coefficient' => 2],
        'Éducation civique et morale' => ['code' => 'ECM', 'description' => 'Valeurs de la Nouvelle Citoyenneté', 'default_coefficient' => 2],
        'Anglais' => ['code' => 'ANG', 'description' => 'Langue anglaise', 'default_coefficient' => 2],
        'Physique' => ['code' => 'PHY', 'description' => 'Sciences physiques', 'default_coefficient' => 3],
        'Chimie' => ['code' => 'CHI', 'description' => 'Sciences de la matière', 'default_coefficient' => 3],
        'Biologie' => ['code' => 'BIO', 'description' => 'Sciences de la vie', 'default_coefficient' => 3],
        'Informatique' => ['code' => 'INFO', 'description' => 'TIC — Technologies de l\'information', 'default_coefficient' => 2],
        'Technologie' => ['code' => 'TECH', 'description' => 'Technologie et travaux pratiques', 'default_coefficient' => 2],
        'Éducation à la Vie' => ['code' => 'EV', 'description' => 'Éducation à la Vie et à l\'Amour', 'default_coefficient' => 1],
        'Philosophie' => ['code' => 'PHIL', 'description' => 'Pensée critique et histoire des idées', 'default_coefficient' => 2],
        'Algèbre' => ['code' => 'ALG', 'description' => 'Algèbre (secondaire scientifique)', 'default_coefficient' => 4],
        'Géométrie analytique' => ['code' => 'GA', 'description' => 'Géométrie analytique', 'default_coefficient' => 3],
        'Trigonométrie' => ['code' => 'TRIG', 'description' => 'Trigonométrie', 'default_coefficient' => 3],
        'Comptabilité' => ['code' => 'COMPT', 'description' => 'Comptabilité générale', 'default_coefficient' => 4],
        'Économie' => ['code' => 'ECO', 'description' => 'Économie générale', 'default_coefficient' => 3],
        'Droit commercial' => ['code' => 'DC', 'description' => 'Droit commercial et des affaires', 'default_coefficient' => 2],
        'Informatique de gestion' => ['code' => 'INFOG', 'description' => 'Informatique appliquée à la gestion', 'default_coefficient' => 3],
        'Mathématiques appliquées' => ['code' => 'MAP', 'description' => 'Mathématiques appliquées (techniques)', 'default_coefficient' => 4],
        'Dessin technique' => ['code' => 'DT', 'description' => 'Dessin technique et industrial', 'default_coefficient' => 3],
        'Pratique d\'atelier' => ['code' => 'PA', 'description' => 'Travaux pratiques d\'atelier', 'default_coefficient' => 4],
        'Pédagogie générale' => ['code' => 'PED', 'description' => 'Pédagogie générale', 'default_coefficient' => 4],
        'Psychologie' => ['code' => 'PSY', 'description' => 'Psychologie de l\'éducation', 'default_coefficient' => 3],
        'Didactique' => ['code' => 'DID', 'description' => 'Didactique des disciplines', 'default_coefficient' => 3],
        'Sociologie' => ['code' => 'SOC', 'description' => 'Sociologie de l\'éducation', 'default_coefficient' => 2],
        'Pratique professionnelle' => ['code' => 'PP', 'description' => 'Stage et pratique professionnelle', 'default_coefficient' => 4],
        'Latin' => ['code' => 'LAT', 'description' => 'Langue et culture latines', 'default_coefficient' => 3],
        'Littérature approfondie' => ['code' => 'LIT', 'description' => 'Littérature francophone approfondie', 'default_coefficient' => 4],
        'Atelier mécanique' => ['code' => 'ATM', 'description' => 'Atelier mécanique', 'default_coefficient' => 4],
        'Atelier électricité' => ['code' => 'ATE', 'description' => 'Atelier électricité', 'default_coefficient' => 4],
        'Atelier électronique' => ['code' => 'ATEL', 'description' => 'Atelier électronique', 'default_coefficient' => 4],
        'Atelier pétrochimie' => ['code' => 'ATP', 'description' => 'Atelier pétrochimie', 'default_coefficient' => 4],
        'Atelier construction' => ['code' => 'ATC', 'description' => 'Atelier construction', 'default_coefficient' => 4],
        'Coupe et couture pratique' => ['code' => 'CCP', 'description' => 'Pratique coupe et couture', 'default_coefficient' => 4],
        'Hôtellerie pratique' => ['code' => 'HOTP', 'description' => 'Pratique hôtellerie-restauration', 'default_coefficient' => 4],
        'Agriculture pratique' => ['code' => 'AGRP', 'description' => 'Pratique agricole', 'default_coefficient' => 4],
        ...$ctebSubjects['catalog'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Matières par groupe de niveaux (coefficient surcharge optionnel)
    |--------------------------------------------------------------------------
    */
    'groups' => [
        'primaire_debut' => [
            ['name' => 'Français', 'coefficient' => 4],
            ['name' => 'Mathématiques', 'coefficient' => 4],
            ['name' => 'Langues nationales', 'coefficient' => 2],
            ['name' => 'Éveil scientifique', 'coefficient' => 2],
            ['name' => 'Arts plastiques', 'coefficient' => 1],
            ['name' => 'Musique', 'coefficient' => 1],
            ['name' => 'Éducation physique', 'coefficient' => 1],
        ],
        'primaire_fin' => [
            ['name' => 'Français', 'coefficient' => 4],
            ['name' => 'Mathématiques', 'coefficient' => 4],
            ['name' => 'Langues nationales', 'coefficient' => 2],
            ['name' => 'Éveil scientifique', 'coefficient' => 2],
            ['name' => 'Arts plastiques', 'coefficient' => 1],
            ['name' => 'Musique', 'coefficient' => 1],
            ['name' => 'Éducation physique', 'coefficient' => 1],
            ['name' => 'Sciences naturelles', 'coefficient' => 2],
            ['name' => 'Géographie', 'coefficient' => 2],
            ['name' => 'Histoire', 'coefficient' => 2],
            ['name' => 'Éducation civique et morale', 'coefficient' => 2],
        ],
        'cteb' => $ctebSubjects['curriculum'],
        'secondaire_common' => [
            ['name' => 'Français', 'coefficient' => 4],
            ['name' => 'Anglais', 'coefficient' => 2],
            ['name' => 'Éducation à la Vie', 'coefficient' => 1],
            ['name' => 'Histoire', 'coefficient' => 2],
            ['name' => 'Géographie', 'coefficient' => 2],
            ['name' => 'Éducation civique et morale', 'coefficient' => 2],
            ['name' => 'Éducation physique', 'coefficient' => 1],
        ],
        'philosophy' => [
            ['name' => 'Philosophie', 'coefficient' => 2],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Extensions par option (humanités)
    |--------------------------------------------------------------------------
    */
    'option_extensions' => [
        'Scientifique Math-Physique' => [
            ['name' => 'Algèbre', 'coefficient' => 4],
            ['name' => 'Géométrie analytique', 'coefficient' => 3],
            ['name' => 'Trigonométrie', 'coefficient' => 3],
            ['name' => 'Physique', 'coefficient' => 4],
            ['name' => 'Chimie', 'coefficient' => 4],
            ['name' => 'Biologie', 'coefficient' => 3],
        ],
        'Scientifique Biologie-Chimie' => [
            ['name' => 'Algèbre', 'coefficient' => 4],
            ['name' => 'Géométrie analytique', 'coefficient' => 3],
            ['name' => 'Trigonométrie', 'coefficient' => 3],
            ['name' => 'Physique', 'coefficient' => 3],
            ['name' => 'Chimie', 'coefficient' => 4],
            ['name' => 'Biologie', 'coefficient' => 4],
        ],
        'Littéraire' => [
            ['name' => 'Littérature approfondie', 'coefficient' => 4],
        ],
        'Latin-Philosophie' => [
            ['name' => 'Latin', 'coefficient' => 3],
            ['name' => 'Littérature approfondie', 'coefficient' => 4],
        ],
        'Pédagogique générale' => [
            ['name' => 'Pédagogie générale', 'coefficient' => 4],
            ['name' => 'Psychologie', 'coefficient' => 3],
            ['name' => 'Didactique', 'coefficient' => 3],
            ['name' => 'Sociologie', 'coefficient' => 2],
            ['name' => 'Pratique professionnelle', 'coefficient' => 4],
        ],
        'Commerciale & Gestion' => [
            ['name' => 'Comptabilité', 'coefficient' => 4],
            ['name' => 'Économie', 'coefficient' => 3],
            ['name' => 'Droit commercial', 'coefficient' => 2],
            ['name' => 'Informatique de gestion', 'coefficient' => 3],
        ],
        'Secrétariat administratif' => [
            ['name' => 'Comptabilité', 'coefficient' => 3],
            ['name' => 'Économie', 'coefficient' => 2],
            ['name' => 'Droit commercial', 'coefficient' => 2],
            ['name' => 'Informatique de gestion', 'coefficient' => 4],
        ],
        'Informatique de gestion' => [
            ['name' => 'Comptabilité', 'coefficient' => 3],
            ['name' => 'Économie', 'coefficient' => 2],
            ['name' => 'Droit commercial', 'coefficient' => 2],
            ['name' => 'Informatique de gestion', 'coefficient' => 5],
        ],
        'Mécanique' => [
            ['name' => 'Mathématiques appliquées', 'coefficient' => 4],
            ['name' => 'Dessin technique', 'coefficient' => 3],
            ['name' => 'Atelier mécanique', 'coefficient' => 4],
            ['name' => 'Pratique d\'atelier', 'coefficient' => 4],
        ],
        'Électricité' => [
            ['name' => 'Mathématiques appliquées', 'coefficient' => 4],
            ['name' => 'Dessin technique', 'coefficient' => 3],
            ['name' => 'Atelier électricité', 'coefficient' => 4],
            ['name' => 'Pratique d\'atelier', 'coefficient' => 4],
        ],
        'Électronique' => [
            ['name' => 'Mathématiques appliquées', 'coefficient' => 4],
            ['name' => 'Dessin technique', 'coefficient' => 3],
            ['name' => 'Atelier électronique', 'coefficient' => 4],
            ['name' => 'Pratique d\'atelier', 'coefficient' => 4],
        ],
        'Pétrochimie' => [
            ['name' => 'Mathématiques appliquées', 'coefficient' => 4],
            ['name' => 'Dessin technique', 'coefficient' => 3],
            ['name' => 'Atelier pétrochimie', 'coefficient' => 4],
            ['name' => 'Pratique d\'atelier', 'coefficient' => 4],
        ],
        'Construction' => [
            ['name' => 'Mathématiques appliquées', 'coefficient' => 4],
            ['name' => 'Dessin technique', 'coefficient' => 3],
            ['name' => 'Atelier construction', 'coefficient' => 4],
            ['name' => 'Pratique d\'atelier', 'coefficient' => 4],
        ],
        'Coupe et couture' => [
            ['name' => 'Coupe et couture pratique', 'coefficient' => 5],
            ['name' => 'Pratique d\'atelier', 'coefficient' => 3],
        ],
        'Hôtellerie' => [
            ['name' => 'Hôtellerie pratique', 'coefficient' => 5],
            ['name' => 'Pratique d\'atelier', 'coefficient' => 3],
        ],
        'Agriculture' => [
            ['name' => 'Agriculture pratique', 'coefficient' => 5],
            ['name' => 'Pratique d\'atelier', 'coefficient' => 3],
        ],
    ],
];
