<?php

/**
 * Matières officielles CTEB 7e / 8e — alignées sur le bulletin national.
 * Source unique pour le programme scolaire (curriculum) et le bulletin.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Catalogue (entrées à fusionner dans curriculum_rdc.catalog)
    |--------------------------------------------------------------------------
    */
    'catalog' => [
        'Arithmétique' => ['code' => 'ARTH', 'description' => 'Arithmétique (CTEB)', 'default_coefficient' => 4],
        'Statistique' => ['code' => 'STAT', 'description' => 'Statistique (CTEB)', 'default_coefficient' => 4],
        'Géométrie' => ['code' => 'GEOM', 'description' => 'Géométrie plane et dans l\'espace (CTEB)', 'default_coefficient' => 4],
        'Anatomie' => ['code' => 'ANAT', 'description' => 'Anatomie humaine (CTEB)', 'default_coefficient' => 3],
        'Botanique' => ['code' => 'BOT', 'description' => 'Botanique (CTEB)', 'default_coefficient' => 3],
        'Nutrition' => ['code' => 'NUT', 'description' => 'Nutrition (CTEB)', 'default_coefficient' => 3],
        'Zoologie' => ['code' => 'ZOO', 'description' => 'Zoologie (CTEB)', 'default_coefficient' => 3],
        'Sciences Physiques' => ['code' => 'SPHY', 'description' => 'Sciences physiques (CTEB)', 'default_coefficient' => 3],
        'Techn. d\'Info. & Com (TIC)' => ['code' => 'TIC', 'description' => 'Technologies de l\'information et de la communication (CTEB)', 'default_coefficient' => 3],
        'Religion' => ['code' => 'REL', 'description' => 'Éducation religieuse (CTEB)', 'default_coefficient' => 2],
        'Education à la vie' => ['code' => 'EVCT', 'description' => 'Éducation à la vie et à l\'amour (CTEB)', 'default_coefficient' => 2],
        'Ed. civique et morale' => ['code' => 'ECMCT', 'description' => 'Éducation civique et morale (CTEB)', 'default_coefficient' => 2],
        'Dessin' => ['code' => 'DESS', 'description' => 'Dessin et arts plastiques (CTEB)', 'default_coefficient' => 2],
        'Ed. Physique & Sportive' => ['code' => 'EPSCT', 'description' => 'Éducation physique et sportive (CTEB)', 'default_coefficient' => 2],
    ],

    /*
    |--------------------------------------------------------------------------
    | Programme 7EB / 8EB (22 branches du bulletin)
    |--------------------------------------------------------------------------
    */
    'curriculum' => [
        ['name' => 'Arithmétique', 'coefficient' => 4],
        ['name' => 'Statistique', 'coefficient' => 4],
        ['name' => 'Géométrie', 'coefficient' => 4],
        ['name' => 'Algèbre', 'coefficient' => 4],
        ['name' => 'Anatomie', 'coefficient' => 3],
        ['name' => 'Botanique', 'coefficient' => 3],
        ['name' => 'Nutrition', 'coefficient' => 3],
        ['name' => 'Zoologie', 'coefficient' => 3],
        ['name' => 'Chimie', 'coefficient' => 3],
        ['name' => 'Sciences Physiques', 'coefficient' => 3],
        ['name' => 'Technologie', 'coefficient' => 3],
        ['name' => 'Techn. d\'Info. & Com (TIC)', 'coefficient' => 3],
        ['name' => 'Anglais', 'coefficient' => 4],
        ['name' => 'Français', 'coefficient' => 4],
        ['name' => 'Religion', 'coefficient' => 2],
        ['name' => 'Education à la vie', 'coefficient' => 2],
        ['name' => 'Ed. civique et morale', 'coefficient' => 2],
        ['name' => 'Histoire', 'coefficient' => 3],
        ['name' => 'Géographie', 'coefficient' => 3],
        ['name' => 'Dessin', 'coefficient' => 2],
        ['name' => 'Musique', 'coefficient' => 2],
        ['name' => 'Ed. Physique & Sportive', 'coefficient' => 2],
    ],
];
