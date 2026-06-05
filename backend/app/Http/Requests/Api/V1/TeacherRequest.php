<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Teacher;
use App\Support\AdminScopeContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class TeacherRequest extends FormRequest
{
    private const GENDERS = ['F', 'M'];

    private const CONTRACT_TYPES = ['Permanent', 'Vacataire'];

    public const SECONDARY_ROLE_PRINCIPAL = 'principal';

    public const SECONDARY_ROLE_SPECIALIST = 'specialist';

    public const SECONDARY_ROLES = [
        self::SECONDARY_ROLE_PRINCIPAL,
        self::SECONDARY_ROLE_SPECIALIST,
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach ([
            'name',
            'email',
            'registration_number',
            'address',
            'grade',
            'speciality',
            'phone',
        ] as $field) {
            if ($this->has($field)) {
                $trimmed[$field] = trim((string) $this->input($field, ''));
            }
        }

        if ($trimmed !== []) {
            $this->merge($trimmed);
        }

        if ($this->has('email') && $this->input('email') === '') {
            $this->merge(['email' => null]);
        }

        if ($this->has('registration_number') && trim((string) $this->input('registration_number', '')) === '') {
            $this->merge(['registration_number' => null]);
        }

        $teacherType = $this->input('teacher_type', $this->route('teacher')?->teacher_type);
        if ($teacherType === Teacher::TYPE_PRIMAIRE && ! filled($trimmed['speciality'] ?? $this->input('speciality'))) {
            $this->merge(['speciality' => null]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $teacherType = $this->input('teacher_type', $this->route('teacher')?->teacher_type);

            if ($teacherType === Teacher::TYPE_PRIMAIRE && filled($this->input('speciality'))) {
                $validator->errors()->add(
                    'speciality',
                    'Les enseignants du primaire et du maternel n\'ont pas de spécialité.',
                );
            }
        });
    }

    public function rules(): array
    {
        $teacher = $this->route('teacher');
        $teacherId = $teacher?->id;
        $userId = $teacher?->user_id;
        $isCreate = $this->isMethod('post');
        $teacherType = $this->input('teacher_type', $teacher?->teacher_type);
        $isSecondary = $teacherType === Teacher::TYPE_SECONDAIRE;

        return [
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['nullable', 'string', Password::min(8)],
            'teacher_type' => [
                $isCreate ? 'required' : 'sometimes',
                'string',
                Rule::in(AdminScopeContext::allowedTeacherTypes($this->user())),
            ],
            'registration_number' => [
                $isCreate ? 'nullable' : 'prohibited',
                'string', 'max:32',
                Rule::unique('teachers', 'registration_number')->ignore($teacherId),
            ],
            'gender' => ['nullable', 'string', Rule::in(self::GENDERS)],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:255'],
            'grade' => ['nullable', 'string', 'max:128'],
            'contract_type' => ['nullable', 'string', Rule::in(self::CONTRACT_TYPES)],
            'hired_on' => ['nullable', 'date'],
            'speciality' => [
                Rule::requiredIf($isCreate && $isSecondary),
                Rule::prohibitedIf($teacherType === Teacher::TYPE_PRIMAIRE),
                'nullable', 'string', 'max:128',
            ],
            'phone' => ['nullable', 'string', 'max:32'],
            'school_year_id' => ['prohibited'],
            'secondary_role' => ['prohibited'],
            'classroom_id' => ['prohibited'],
            'classroom_ids' => ['prohibited'],
            'subject_id' => ['prohibited'],
            'subject_ids' => ['prohibited'],
        ];
    }
}
