<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Level;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StudentPortalAccountService
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_NOT_CREATED = 'not_created';

    public const STATUS_DISABLED_UNTIL_7E = 'disabled_until_7e';

    public const STATUS_NOT_CREATED_UNTIL_7E = 'not_created_until_7e';

    /**
     * @return array{role:string,name:string,login:string,login_type:string,password:?string,generated:bool}|null
     */
    public function syncForStudent(
        Student $student,
        ?string $preferredEmail = null,
        bool $createMissing = true,
    ): ?array {
        $student->loadMissing(['classroom.level', 'user']);

        if (! $this->isEligible($student)) {
            $this->disableStudentUser($student);

            return null;
        }

        if ($student->user === null) {
            if (! $createMissing) {
                return null;
            }

            return $this->createStudentUser($student, $preferredEmail);
        }

        if ((bool) $student->user->is_active) {
            return null;
        }

        return $this->reactivateStudentUser($student);
    }

    public function isEligible(Student $student): bool
    {
        $student->loadMissing('classroom.level');
        $cycle = $student->classroom?->level?->cycle;

        return in_array($cycle, [Level::CYCLE_CTEB, Level::CYCLE_SECONDAIRE], true);
    }

    public function status(Student $student): string
    {
        $student->loadMissing(['classroom.level', 'user']);
        $hasUser = $student->user !== null;

        if (! $this->isEligible($student)) {
            return $hasUser
                ? self::STATUS_DISABLED_UNTIL_7E
                : self::STATUS_NOT_CREATED_UNTIL_7E;
        }

        if (! $hasUser) {
            return self::STATUS_NOT_CREATED;
        }

        return (bool) $student->user->is_active
            ? self::STATUS_ACTIVE
            : self::STATUS_INACTIVE;
    }

    /**
     * @return array{role:string,name:string,login:string,login_type:string,password:string,generated:bool}
     */
    private function createStudentUser(Student $student, ?string $preferredEmail): array
    {
        $plain = $this->temporaryPassword();
        $user = User::query()->create([
            'name' => $student->full_name,
            'email' => $this->uniqueStudentEmail($student, $preferredEmail),
            'password' => Hash::make($plain),
            'role' => UserRole::Eleve,
            'is_active' => true,
        ]);

        $student->forceFill(['user_id' => $user->id])->save();
        $student->setRelation('user', $user);

        return $this->portalCredential($student, $plain, true);
    }

    /**
     * @return array{role:string,name:string,login:string,login_type:string,password:string,generated:bool}
     */
    private function reactivateStudentUser(Student $student): array
    {
        $plain = $this->temporaryPassword();

        $student->user->forceFill([
            'name' => $student->full_name,
            'password' => Hash::make($plain),
            'is_active' => true,
        ])->save();

        return $this->portalCredential($student, $plain, true);
    }

    private function disableStudentUser(Student $student): void
    {
        if ($student->user === null) {
            return;
        }

        if ((bool) $student->user->is_active) {
            $student->user->forceFill(['is_active' => false])->save();
        }

        $student->user->tokens()->delete();
    }

    private function uniqueStudentEmail(Student $student, ?string $preferredEmail): string
    {
        if (filled($preferredEmail) && ! User::query()->where('email', $preferredEmail)->exists()) {
            return $preferredEmail;
        }

        $base = Str::lower(Str::slug('eleve-'.$student->registration_number));
        $email = "{$base}@malunga.local";
        $suffix = 1;

        while (User::query()->where('email', $email)->exists()) {
            $email = "{$base}-{$suffix}@malunga.local";
            $suffix++;
        }

        return $email;
    }

    /**
     * @return array{role:string,name:string,login:string,login_type:string,password:?string,generated:bool}
     */
    private function portalCredential(Student $student, ?string $password, bool $generated): array
    {
        return [
            'role' => UserRole::Eleve->value,
            'name' => $student->full_name,
            'login' => (string) $student->registration_number,
            'login_type' => 'matricule',
            'password' => $password,
            'generated' => $generated,
        ];
    }

    private function temporaryPassword(): string
    {
        return Str::random(10);
    }
}
