<?php

namespace App\Services;

use App\Models\ClassRoom;
use App\Models\Student;
use App\Support\SchoolYearContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Import en lot d'élèves depuis un fichier CSV (CDC §4.2 / UC-06).
 *
 * Colonnes attendues (en-tête de la 1re ligne) :
 *   last_name, postnom, first_name,
 *   date_of_birth (YYYY-MM-DD, optionnel),
 *   gender (F|M|Autre, optionnel),
 *   registration_number (optionnel, unique),
 *   classroom (nom complet ex. "6ème A", optionnel),
 *   email (optionnel : crée un compte élève seulement à partir de la 7e CTEB).
 */
class StudentCsvImporter
{
    public const REQUIRED_HEADERS = ['first_name', 'last_name'];

    public const ALLOWED_HEADERS = [
        'last_name',
        'postnom',
        'first_name',
        'date_of_birth',
        'gender',
        'registration_number',
        'classroom',
        'email',
        'notes',
    ];

    public function __construct(
        private readonly StudentPortalAccountService $portalAccounts,
    ) {}

    /**
     * @return array{
     *   created: int,
     *   updated: int,
     *   skipped: int,
     *   errors: array<int, array{line:int, errors:array<int, string>}>,
     *   credentials: array<int, array{email:string, password:string}>,
     *   warnings: array<int, array{line:int, warning:string}>
     * }
     */
    /**
     * @param  array<int, string>|null  $allowedCycles
     */
    public function import(UploadedFile $file, ?int $schoolYearId = null, ?array $allowedCycles = null): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            return [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => [['line' => 0, 'errors' => ['Impossible de lire le fichier.']]],
                'credentials' => [],
                'warnings' => [],
            ];
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);

            return [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => [['line' => 1, 'errors' => ['Fichier vide ou en-tête manquante.']]],
                'credentials' => [],
                'warnings' => [],
            ];
        }

        $headers = array_map(fn ($h) => trim((string) $h), $headers);

        foreach (self::REQUIRED_HEADERS as $required) {
            if (! in_array($required, $headers, true)) {
                fclose($handle);

                return [
                    'created' => 0,
                    'updated' => 0,
                    'skipped' => 0,
                    'errors' => [[
                        'line' => 1,
                        'errors' => ["Colonne obligatoire manquante : {$required}."],
                    ]],
                    'credentials' => [],
                    'warnings' => [],
                ];
            }
        }
        if (! in_array('middle_name', $headers, true) && ! in_array('postnom', $headers, true)) {
            fclose($handle);

            return [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => [[
                    'line' => 1,
                    'errors' => ['Colonne obligatoire manquante : postnom.'],
                ]],
                'credentials' => [],
                'warnings' => [],
            ];
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $credentials = [];
        $warnings = [];
        $schoolYearId ??= SchoolYearContext::currentId();

        $line = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $data = $this->mapRow($headers, $row);
            $rowErrors = $this->validateRow($data, $schoolYearId);
            $cycle = null;
            if (! empty($data['classroom_id'])) {
                $cycle = ClassRoom::query()
                    ->with('level:id,cycle')
                    ->find((int) $data['classroom_id'])
                    ?->level?->cycle;
            }
            if ($allowedCycles !== null && $cycle !== null && ! in_array($cycle, $allowedCycles, true)) {
                $warnings[] = [
                    'line' => $line,
                    'warning' => "Ligne ignorée : la classe appartient au cycle {$cycle}, hors de votre périmètre.",
                ];
                $skipped++;

                continue;
            }

            if (! empty($rowErrors)) {
                $errors[] = ['line' => $line, 'errors' => $rowErrors];
                $skipped++;

                continue;
            }

            try {
                DB::transaction(function () use ($data, $schoolYearId, $line, &$created, &$updated, &$credentials, &$warnings): void {
                    $student = $this->studentForImportRow($data);
                    $payload = [
                        'classroom_id' => $data['classroom_id'] ?? null,
                        'enrollment_school_year_id' => $schoolYearId,
                        'first_name' => $data['first_name'],
                        'last_name' => $data['last_name'],
                        'middle_name' => $data['middle_name'],
                        'date_of_birth' => $data['date_of_birth'] ?? null,
                        'gender' => $data['gender'] ?? null,
                        'registration_number' => $data['registration_number'] ?? null,
                        'notes' => $data['notes'] ?? null,
                    ];

                    if ($student) {
                        $student->update($payload);
                        $updated++;

                        $this->syncImportedStudentPortal($student, $data, $line, $credentials, $warnings);

                        return;
                    }

                    $student = Student::query()->create($payload);
                    $created++;
                    $this->syncImportedStudentPortal($student, $data, $line, $credentials, $warnings);
                });
            } catch (\Throwable $e) {
                $errors[] = ['line' => $line, 'errors' => [$e->getMessage()]];
                $skipped++;
            }
        }

        fclose($handle);

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'credentials' => $credentials,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array{email:string, password:string}>  $credentials
     * @param  array<int, array{line:int, warning:string}>  $warnings
     */
    private function syncImportedStudentPortal(
        Student $student,
        array $data,
        int $line,
        array &$credentials,
        array &$warnings,
    ): void {
        $student = $student->fresh(['classroom.level', 'user']);
        $hasEmail = filled($data['email'] ?? null);
        $credential = $this->portalAccounts->syncForStudent(
            $student,
            $hasEmail ? (string) $data['email'] : null,
            $hasEmail,
        );

        if (! $this->portalAccounts->isEligible($student)) {
            if ($hasEmail) {
                $warnings[] = [
                    'line' => $line,
                    'warning' => 'Compte élève non créé : le portail élève est disponible à partir de la 7e CTEB.',
                ];
            }

            return;
        }

        if ($credential !== null && $credential['generated'] && filled($credential['password'])) {
            $student->loadMissing('user');
            $credentials[] = [
                'email' => $student->user?->email ?? (string) ($data['email'] ?? ''),
                'password' => (string) $credential['password'],
            ];
        }
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapRow(array $headers, array $row): array
    {
        $data = [];
        foreach ($headers as $i => $header) {
            if (! in_array($header, self::ALLOWED_HEADERS, true) && $header !== 'middle_name') {
                continue;
            }
            $value = isset($row[$i]) ? trim((string) $row[$i]) : '';
            $data[$header] = $value === '' ? null : $value;
        }
        if (! empty($data['postnom']) && empty($data['middle_name'])) {
            $data['middle_name'] = $data['postnom'];
        }

        if (! empty($data['classroom'])) {
            $name = $data['classroom'];
            $classroom = ClassRoom::query()->whereRaw(
                'LOWER(level_id || \'-\' || section) = ?',
                [strtolower((string) $name)],
            )->first();
            if (! $classroom) {
                $classroom = ClassRoom::query()
                    ->with('level')
                    ->get()
                    ->first(fn (ClassRoom $c) => mb_strtolower($c->full_name) === mb_strtolower((string) $name));
            }
            $data['classroom_id'] = $classroom?->id;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function validateRow(array $data, ?int $schoolYearId): array
    {
        $errors = [];

        if (empty($data['first_name'])) {
            $errors[] = 'first_name est requis.';
        }
        if (empty($data['last_name'])) {
            $errors[] = 'last_name est requis.';
        }
        if (empty($data['middle_name'])) {
            $errors[] = 'postnom est requis.';
        }

        if (! empty($data['gender']) && ! in_array($data['gender'], ['F', 'M', 'Autre'], true)) {
            $errors[] = 'gender doit être F, M ou Autre.';
        }

        if (! empty($data['date_of_birth'])) {
            $ts = strtotime((string) $data['date_of_birth']);
            if ($ts === false || $ts >= time()) {
                $errors[] = 'date_of_birth invalide (format YYYY-MM-DD, dans le passé).';
            }
        }

        if (! empty($data['registration_number'])) {
            $existing = $this->studentForImportRow($data);
            if (
                $existing
                && (
                    $schoolYearId === null
                    || (
                        $existing->enrollment_school_year_id !== null
                        && $existing->enrollment_school_year_id !== $schoolYearId
                    )
                )
            ) {
                $errors[] = "registration_number déjà utilisé sur une autre année scolaire : {$data['registration_number']}.";
            }
        }

        if (! empty($data['email']) && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'email invalide.';
        }

        if (! empty($data['classroom']) && empty($data['classroom_id'])) {
            $errors[] = "classroom introuvable : {$data['classroom']}.";
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function studentForImportRow(array $data): ?Student
    {
        if (empty($data['registration_number'])) {
            return null;
        }

        return Student::query()
            ->where('registration_number', $data['registration_number'])
            ->first();
    }
}
