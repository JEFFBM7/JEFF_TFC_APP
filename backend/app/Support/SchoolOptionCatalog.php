<?php

namespace App\Support;

use App\Models\SchoolOption;

/**
 * Options officielles des Humanités (EPST/RDC) — source unique pour seeders et générateurs.
 */
class SchoolOptionCatalog
{
    /** @return list<array{name: string, abbreviation: string, filiere: string}> */
    public static function rows(): array
    {
        return [
            ['name' => 'Scientifique', 'abbreviation' => 'SCI', 'filiere' => SchoolOption::FILIERE_GENERALE],
            ['name' => 'Littéraire', 'abbreviation' => 'LIT', 'filiere' => SchoolOption::FILIERE_GENERALE],
            ['name' => 'Pédagogique générale', 'abbreviation' => 'PEDA', 'filiere' => SchoolOption::FILIERE_GENERALE],
            ['name' => 'Latin-Philosophie', 'abbreviation' => 'LATPH', 'filiere' => SchoolOption::FILIERE_GENERALE],
            ['name' => 'Mécanique', 'abbreviation' => 'MECA', 'filiere' => SchoolOption::FILIERE_TECHNIQUE],
            ['name' => 'Électricité', 'abbreviation' => 'ELEC', 'filiere' => SchoolOption::FILIERE_TECHNIQUE],
            ['name' => 'Électronique', 'abbreviation' => 'ELECT', 'filiere' => SchoolOption::FILIERE_TECHNIQUE],
            ['name' => 'Pétrochimie', 'abbreviation' => 'PETRO', 'filiere' => SchoolOption::FILIERE_TECHNIQUE],
            ['name' => 'Construction', 'abbreviation' => 'CONST', 'filiere' => SchoolOption::FILIERE_TECHNIQUE],
            ['name' => 'Commerciale & Gestion', 'abbreviation' => 'COMG', 'filiere' => SchoolOption::FILIERE_TECHNIQUE],
            ['name' => 'Secrétariat administratif', 'abbreviation' => 'SEC', 'filiere' => SchoolOption::FILIERE_TECHNIQUE],
            ['name' => 'Informatique de gestion', 'abbreviation' => 'INFOG', 'filiere' => SchoolOption::FILIERE_TECHNIQUE],
            ['name' => 'Coupe et couture', 'abbreviation' => 'COUT', 'filiere' => SchoolOption::FILIERE_PROFESSIONNELLE],
            ['name' => 'Hôtellerie', 'abbreviation' => 'HOTEL', 'filiere' => SchoolOption::FILIERE_PROFESSIONNELLE],
            ['name' => 'Agriculture', 'abbreviation' => 'AGRI', 'filiere' => SchoolOption::FILIERE_PROFESSIONNELLE],
        ];
    }

    /** @return list<string> */
    public static function commercialOptionNames(): array
    {
        return ['Commerciale & Gestion', 'Secrétariat administratif', 'Informatique de gestion'];
    }

    /** @return list<string> */
    public static function technicalWorkshopOptionNames(): array
    {
        return ['Mécanique', 'Électricité', 'Électronique', 'Pétrochimie', 'Construction'];
    }

    /** @return list<string> */
    public static function pedagogicalOptionNames(): array
    {
        return ['Pédagogique générale'];
    }

    /** @return list<string> */
    public static function professionalOptionNames(): array
    {
        return ['Coupe et couture', 'Hôtellerie', 'Agriculture'];
    }
}
