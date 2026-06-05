<?php

$ctebSubjects = require __DIR__.'/cteb_subjects.php';
$bulletinRows = require __DIR__.'/cteb_bulletin_rows.php';

$legacyAliases = $bulletinRows['legacy_aliases'];

$resolveRows = static function (array $rows) use ($legacyAliases): array {
    return array_map(static function (array $row) use ($legacyAliases): array {
        $aliases = $row['aliases'] ?? [];
        if (isset($row['alias_key'])) {
            $aliases = array_merge($legacyAliases[$row['alias_key']] ?? [], $aliases);
            unset($row['alias_key']);
        }
        if (isset($row['extra_aliases'])) {
            $aliases = array_merge($aliases, $row['extra_aliases']);
            unset($row['extra_aliases']);
        }
        if ($aliases !== []) {
            $row['aliases'] = array_values(array_unique($aliases));
        }

        return $row;
    }, $rows);
};

$rowsByGrade = [];
foreach ($bulletinRows['rows_by_grade'] as $grade => $rows) {
    $rowsByGrade[$grade] = $resolveRows($rows);
}

return [
    'school' => [
        'republic' => 'RÉPUBLIQUE DÉMOCRATIQUE DU CONGO',
        'ministry' => "MINISTÈRE DE L'ÉDUCATION NATIONALE ET NOUVELLE CITOYENNETÉ",
        'name' => 'Complexe scolaire MALUNGA',
    ],

    'subject_names' => array_column($ctebSubjects['curriculum'], 'name'),

    'rows_by_grade' => $rowsByGrade,

    /** @deprecated Utiliser rows_by_grade[7|8] */
    'rows' => $rowsByGrade[7],
];
