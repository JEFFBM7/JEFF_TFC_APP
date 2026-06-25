<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AttachParentRequest;
use App\Http\Requests\Api\V1\StudentRequest;
use App\Http\Resources\Api\V1\StudentResource;
use App\Models\ParentProfile;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Services\StudentCsvImporter;
use App\Services\StudentPortalAccountService;
use App\Services\StudentTimelineService;
use App\Support\AdminScopeContext;
use App\Support\SchoolYearContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $yearId = SchoolYearContext::requestedOrCurrentId($request);

        $query = Student::query()->with([
            'classroom.level', 'enrollmentSchoolYear', 'user',
            // Inscription de l'année consultée → classe + statut de CETTE année
            // (et non le cache année-courante porté par students.classroom_id).
            'enrollments' => fn ($q) => $q->where('school_year_id', $yearId)->with('classroom.level'),
        ]);
        AdminScopeContext::applyStudentScope($query, $request);

        if ($request->filled('classroom_id')) {
            AdminScopeContext::assertClassroomAllowed($request->user(), $request->integer('classroom_id'));
            $classroomId = $request->integer('classroom_id');
            if ($request->filled('school_year_id')) {
                // Classe de l'année consultée (via enrollments) plutôt que le cache.
                $query->whereHas('enrollments', fn ($q) => $q
                    ->where('school_year_id', $yearId)
                    ->where('classroom_id', $classroomId));
            } else {
                $query->where('classroom_id', $classroomId);
            }
        }

        SchoolYearContext::applyStudentEnrollmentYearId($query, $yearId);

        if ($request->filled('cycle')) {
            if (! AdminScopeContext::requestedCycleIsAllowed($request)) {
                $query->whereRaw('1 = 0');
            }
            $cycle = $request->string('cycle')->value();
            if ($request->filled('school_year_id')) {
                // Cycle de la classe de l'année consultée (via enrollments).
                $query->whereHas('enrollments', fn ($q) => $q
                    ->where('school_year_id', $yearId)
                    ->whereHas('classroom.level', fn ($lq) => $lq->where('cycle', $cycle)));
            } else {
                $query->whereHas('classroom.level', fn ($levelQuery) => $levelQuery->where('cycle', $cycle));
            }
        }

        if ($request->filled('search')) {
            $term = mb_strtolower($request->string('search')->value());
            $like = '%'.$term.'%';
            $driver = $query->getModel()->getConnection()->getDriverName();
            $op = $driver === 'pgsql' ? 'ilike' : 'like';
            $query->where(function ($q) use ($like, $op): void {
                $q->where('first_name', $op, $like)
                    ->orWhere('last_name', $op, $like)
                    ->orWhere('middle_name', $op, $like)
                    ->orWhere('registration_number', $op, $like);
            });
        }

        return StudentResource::collection(
            $query->orderBy('last_name')->orderBy('middle_name')->orderBy('first_name')->paginate(50),
        );
    }

    public function store(StudentRequest $request, StudentPortalAccountService $portalAccounts): JsonResponse
    {
        $portalCredentials = [];

        $student = DB::transaction(function () use ($request, $portalAccounts, &$portalCredentials): Student {
            $data = $this->normalizeNullableFields($request->validated());
            $data = $this->ensureEnrollmentSchoolYear($data);
            AdminScopeContext::assertClassroomAllowed($request->user(), (int) $data['classroom_id']);
            $student = Student::query()->create($data);

            $this->fillGeneratedIdentifiers($student);
            if ($studentCredential = $portalAccounts->syncForStudent($student->fresh(['classroom.level', 'user']))) {
                $portalCredentials[] = $studentCredential;
            }

            $portalCredentials = [
                ...$portalCredentials,
                ...$this->createAndAttachParentsFromStudentData($student, $data),
            ];

            return $student->fresh();
        });

        return StudentResource::make($student->load(['classroom.level', 'enrollmentSchoolYear', 'parents.user', 'user']))
            ->additional([
                'meta' => [
                    'portal_credentials' => $portalCredentials,
                ],
            ])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Student $student): StudentResource
    {
        AdminScopeContext::assertStudentAllowed(request()->user(), $student);

        return StudentResource::make(
            $student->load(['classroom.level', 'enrollmentSchoolYear', 'parents.user', 'user']),
        );
    }

    public function timeline(Request $request, Student $student, StudentTimelineService $timeline): JsonResponse
    {
        $this->authorizeTimelineAccess($request, $student);

        return response()->json([
            'data' => $timeline->forStudent(
                $student->loadMissing('classroom'),
                SchoolYearContext::requestedOrCurrent($request),
            ),
        ]);
    }

    public function update(
        StudentRequest $request,
        Student $student,
        StudentPortalAccountService $portalAccounts,
    ): JsonResponse {
        $portalCredentials = [];
        $data = $this->normalizeNullableFields($request->validated());
        AdminScopeContext::assertStudentAllowed($request->user(), $student);
        AdminScopeContext::assertClassroomAllowed($request->user(), (int) $data['classroom_id']);

        $student = DB::transaction(function () use ($data, $student, $portalAccounts, &$portalCredentials): Student {
            // Si le client n'envoie pas de valeur explicite, on garde celle déjà en base
            // (et si l'élève n'en avait aucune, on retombe sur l'année courante).
            if (! array_key_exists('enrollment_school_year_id', $data) || $data['enrollment_school_year_id'] === null) {
                $data['enrollment_school_year_id'] = $student->enrollment_school_year_id
                    ?? SchoolYearContext::currentId();
            }

            if ($data['enrollment_school_year_id'] === null) {
                abort(422, "Aucune année scolaire courante n'est définie : impossible de mettre à jour l'élève sans préciser enrollment_school_year_id.");
            }

            $student->update($data);
            $this->fillGeneratedIdentifiers($student);

            $student = $student->fresh(['classroom.level', 'enrollmentSchoolYear', 'user']);
            if ($studentCredential = $portalAccounts->syncForStudent($student)) {
                $portalCredentials[] = $studentCredential;
            }

            return $student->fresh(['classroom.level', 'enrollmentSchoolYear', 'user']);
        });

        return StudentResource::make($student)
            ->additional([
                'meta' => [
                    'portal_credentials' => $portalCredentials,
                ],
            ])
            ->response();
    }

    public function destroy(Student $student): JsonResponse
    {
        AdminScopeContext::assertStudentAllowed(request()->user(), $student);

        DB::transaction(function () use ($student) {
            $user = $student->user;
            $student->delete();
            if ($user) {
                $user->delete();
            }
        });

        return response()->json(null, 204);
    }

    // ─── Liens élève ↔ parent ────────────────────────────────────────────────

    public function attachParent(AttachParentRequest $request, Student $student): JsonResponse
    {
        AdminScopeContext::assertStudentAllowed($request->user(), $student);

        $data = $request->validated();
        $student->parents()->syncWithoutDetaching([
            $data['parent_profile_id'] => ['relation' => $data['relation']],
        ]);

        return response()->json([
            'message' => 'Parent rattaché.',
        ], 200);
    }

    public function detachParent(Student $student, ParentProfile $parent): JsonResponse
    {
        AdminScopeContext::assertStudentAllowed(request()->user(), $student);

        $student->parents()->detach($parent->id);

        return response()->json(null, 204);
    }

    // ─── Import CSV (CDC §4.2 / UC-06) ───────────────────────────────────────

    /** Télécharge le modèle CSV vide. */
    public function importTemplate(): StreamedResponse
    {
        $headers = StudentCsvImporter::ALLOWED_HEADERS;

        $callback = function () use ($headers): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, $headers);
            fputcsv($out, [
                'Kabongo',
                'Ilunga',
                'Marie',
                '2012-04-15',
                'F',
                'M001',
                '6ème A',
                '',
                'Élève transférée',
            ]);
            fclose($out);
        };

        return response()->streamDownload($callback, 'modele-eleves.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** Importe un fichier CSV d'élèves. */
    public function import(Request $request, StudentCsvImporter $importer): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'school_year_id' => ['nullable', 'integer', 'exists:school_years,id'],
        ]);

        $result = $importer->import(
            $request->file('file'),
            SchoolYearContext::requestedOrCurrentId($request),
            AdminScopeContext::allowedCycles($request->user()),
        );

        return response()->json([
            'message' => sprintf(
                '%d élève(s) importé(s), %d mis à jour, %d ignoré(s).',
                $result['created'],
                $result['updated'],
                $result['skipped'],
            ),
            'created' => $result['created'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'errors' => $result['errors'],
            'credentials' => $result['credentials'],
            'warnings' => $result['warnings'],
        ]);
    }

    private function normalizeNullableFields(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value) && trim($value) === '') {
                $data[$key] = null;
            }
        }

        return $data;
    }

    private function authorizeTimelineAccess(Request $request, Student $student): void
    {
        $user = $request->user();
        if ($user?->role === UserRole::Admin) {
            AdminScopeContext::assertStudentAllowed($user, $student);
            return;
        }

        if ($user?->role !== UserRole::Enseignant) {
            abort(403, 'Accès refusé.');
        }

        $teacher = Teacher::query()->where('user_id', $user->id)->first();
        if ($teacher === null) {
            abort(403, 'Accès refusé.');
        }

        $schoolYearId = SchoolYearContext::requestedOrCurrentId($request)
            ?? $student->enrollment_school_year_id;

        $canAccess = TeacherAssignment::query()
            ->where('teacher_id', $teacher->id)
            ->where('classroom_id', $student->classroom_id)
            ->when($schoolYearId !== null, fn ($query) => $query->where('school_year_id', $schoolYearId))
            ->exists();

        if (! $canAccess) {
            abort(403, 'Accès refusé.');
        }
    }

    /**
     * Garantit que `enrollment_school_year_id` est renseigné lors de la création.
     * Fallback automatique sur l'année scolaire courante si le client ne précise rien.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function ensureEnrollmentSchoolYear(array $data): array
    {
        if (empty($data['enrollment_school_year_id'])) {
            $currentId = SchoolYearContext::currentId();

            if ($currentId === null) {
                abort(422, "Aucune année scolaire courante n'est définie : précise enrollment_school_year_id ou définis une année courante.");
            }

            $data['enrollment_school_year_id'] = $currentId;
        }

        return $data;
    }

    /**
     * @return array<int, array{role:string,name:string,login:string,login_type:string,password:?string,generated:bool}>
     */
    private function createAndAttachParentsFromStudentData(Student $student, array $data): array
    {
        $candidates = $this->parentCandidates($student, $data);
        $credentials = [];

        foreach ($candidates as $candidate) {
            $parent = $this->findExistingParentProfile(
                $candidate['name'],
                $candidate['email'],
                $candidate['phone'],
            );
            $credential = null;

            if (! $parent) {
                [$parent, $credential] = $this->createParentProfile($student, $candidate);
            } else {
                $credential = $this->portalCredential(
                    UserRole::Parent->value,
                    $candidate['name'],
                    $this->parentLogin($parent, $candidate),
                    $this->parentLoginType($parent, $candidate),
                    null,
                    false,
                );
            }

            if (blank($parent->address) && filled($candidate['address'])) {
                $parent->forceFill(['address' => $candidate['address']])->save();
            }

            $student->parents()->syncWithoutDetaching([
                $parent->id => ['relation' => $candidate['relation']],
            ]);

            $credentials[] = $credential;
        }

        return $credentials;
    }

    /**
     * @return array<int, array{name:string, relation:string, phone:?string, email:?string, address:?string}>
     */
    private function parentCandidates(Student $student, array $data): array
    {
        $emailAssigned = false;
        $candidates = [];
        $address = $data['residential_address'] ?? null;

        foreach ([
            ['name' => $data['legal_guardian_name'] ?? null, 'relation' => 'tuteur', 'phone' => $data['primary_phone'] ?? null],
            ['name' => $data['father_name'] ?? null, 'relation' => 'pere', 'phone' => $data['primary_phone'] ?? null],
            ['name' => $data['mother_name'] ?? null, 'relation' => 'mere', 'phone' => $data['secondary_phone'] ?? $data['primary_phone'] ?? null],
        ] as $parent) {
            if (blank($parent['name'])) {
                continue;
            }

            $candidates[] = [
                'name' => $parent['name'],
                'relation' => $parent['relation'],
                'phone' => $parent['phone'],
                'email' => $emailAssigned ? null : ($data['parent_email'] ?? null),
                'address' => $address,
            ];
            $emailAssigned = $emailAssigned || filled($data['parent_email'] ?? null);
        }

        if ($candidates === []) {
            $candidates[] = [
                'name' => 'Parent de '.$student->full_name,
                'relation' => 'tuteur',
                'phone' => $data['primary_phone'] ?? null,
                'email' => $data['parent_email'] ?? null,
                'address' => $address,
            ];
        }

        return $candidates;
    }

    private function findExistingParentProfile(string $name, ?string $email, ?string $phone): ?ParentProfile
    {
        if (filled($email)) {
            $parent = ParentProfile::query()
                ->whereHas('user', function ($query) use ($email): void {
                    $query->where('email', $email)
                        ->where('role', UserRole::Parent->value);
                })
                ->first();

            if ($parent) {
                return $parent;
            }
        }

        if (filled($phone)) {
            return ParentProfile::query()
                ->where('phone', $phone)
                ->whereHas('user', function ($query) use ($name): void {
                    $query->where('name', $name)
                        ->where('role', UserRole::Parent->value);
                })
                ->first();
        }

        return null;
    }

    /**
     * @param  array{name:string, relation:string, phone:?string, email:?string, address:?string}  $candidate
     * @return array{0:ParentProfile,1:array{role:string,name:string,login:string,login_type:string,password:string,generated:bool}}
     */
    private function createParentProfile(Student $student, array $candidate): array
    {
        $plain = $this->temporaryPassword();
        $user = User::query()->create([
            'name' => $candidate['name'],
            'email' => $this->uniqueParentEmail($student, $candidate['relation'], $candidate['email']),
            'password' => Hash::make($plain),
            'role' => UserRole::Parent,
        ]);

        $parent = ParentProfile::query()->create([
            'user_id' => $user->id,
            'phone' => $candidate['phone'],
            'address' => $candidate['address'],
        ]);

        return [
            $parent,
            $this->portalCredential(
                UserRole::Parent->value,
                $candidate['name'],
                $this->parentLogin($parent, $candidate),
                $this->parentLoginType($parent, $candidate),
                $plain,
                true,
            ),
        ];
    }

    private function uniqueParentEmail(Student $student, string $relation, ?string $preferredEmail): string
    {
        if (filled($preferredEmail) && ! User::query()->where('email', $preferredEmail)->exists()) {
            return $preferredEmail;
        }

        $base = sprintf('parent+%d-%s', $student->id, Str::slug($relation));
        $email = "{$base}@malunga.local";
        $suffix = 1;

        while (User::query()->where('email', $email)->exists()) {
            $email = "{$base}-{$suffix}@malunga.local";
            $suffix++;
        }

        return $email;
    }

    /**
     * @param  array{name:string, relation:string, phone:?string, email:?string, address:?string}  $candidate
     */
    private function parentLogin(ParentProfile $parent, array $candidate): string
    {
        if (filled($candidate['email']) && $parent->user?->email === $candidate['email']) {
            return $candidate['email'];
        }

        if (filled($parent->phone)) {
            return $parent->phone;
        }

        return $parent->user?->email ?? '';
    }

    /**
     * @param  array{name:string, relation:string, phone:?string, email:?string, address:?string}  $candidate
     */
    private function parentLoginType(ParentProfile $parent, array $candidate): string
    {
        return filled($candidate['email']) && $parent->user?->email === $candidate['email']
            ? 'email'
            : 'telephone';
    }

    /**
     * @return array{role:string,name:string,login:string,login_type:string,password:?string,generated:bool}
     */
    private function portalCredential(
        string $role,
        string $name,
        string $login,
        string $loginType,
        ?string $password,
        bool $generated,
    ): array {
        return [
            'role' => $role,
            'name' => $name,
            'login' => $login,
            'login_type' => $loginType,
            'password' => $password,
            'generated' => $generated,
        ];
    }

    private function temporaryPassword(): string
    {
        return Str::random(10);
    }

    private function fillGeneratedIdentifiers(Student $student): void
    {
        $updates = [];

        if (blank($student->registration_number)) {
            $updates['registration_number'] = $this->generateRegistrationNumber($student);
        }

        if (blank($student->order_number)) {
            $updates['order_number'] = $this->generateOrderNumber($student);
        }

        if ($updates !== []) {
            $student->forceFill($updates)->save();
        }
    }

    private function generateRegistrationNumber(Student $student): string
    {
        $startDate = SchoolYear::query()
            ->whereKey($student->enrollment_school_year_id)
            ->value('starts_on');
        $year = $startDate ? substr((string) $startDate, 0, 4) : now()->format('Y');
        $base = sprintf('MAL-%s-%05d', $year, $student->id);

        if (! Student::query()->where('registration_number', $base)->whereKeyNot($student->id)->exists()) {
            return $base;
        }

        return sprintf('MAL-%s-%05d-%s', $year, $student->id, Str::upper(Str::random(3)));
    }

    private function generateOrderNumber(Student $student): string
    {
        $startDate = SchoolYear::query()
            ->whereKey($student->enrollment_school_year_id)
            ->value('starts_on');
        $year = $startDate ? substr((string) $startDate, 0, 4) : now()->format('Y');
        $base = sprintf('ORD-%s-%05d', $year, $student->id);

        if (! Student::query()->where('order_number', $base)->whereKeyNot($student->id)->exists()) {
            return $base;
        }

        return sprintf('ORD-%s-%05d-%s', $year, $student->id, Str::upper(Str::random(3)));
    }
}
