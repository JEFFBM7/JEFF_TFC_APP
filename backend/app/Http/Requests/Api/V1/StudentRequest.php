<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentRequest extends FormRequest
{
    private const STATUSES = ['actif', 'redoublant', 'transfere', 'inactif'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach ([
            'first_name',
            'last_name',
            'middle_name',
            'place_of_birth',
            'nationality',
            'registration_number',
            'photo_path',
            'order_number',
            'previous_school',
            'father_name',
            'mother_name',
            'legal_guardian_name',
            'guardian_relationship',
            'primary_phone',
            'secondary_phone',
            'parent_email',
            'residential_address',
            'father_profession',
            'mother_profession',
            'notes',
        ] as $field) {
            if ($this->has($field)) {
                $trimmed[$field] = trim((string) $this->input($field, ''));
            }
        }

        if ($trimmed !== []) {
            $this->merge($trimmed);
        }
    }

    public function rules(): array
    {
        $id = $this->route('student')?->id;

        return [
            'user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Eleve->value),
                Rule::unique('students', 'user_id')->ignore($id),
            ],
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            // Auto-rempli avec l'année scolaire courante par StudentController si absent.
            'enrollment_school_year_id' => ['nullable', 'integer', 'exists:school_years,id'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'place_of_birth' => ['required', 'string', 'max:100'],
            'gender' => ['required', 'string', 'in:F,M'],
            'nationality' => ['required', 'string', 'max:80'],
            'registration_number' => [
                'nullable', 'string', 'max:32',
                Rule::unique('students', 'registration_number')->ignore($id),
            ],
            'photo_path' => ['nullable', 'string', 'max:255'],
            'enrollment_status' => ['required', 'string', Rule::in(self::STATUSES)],
            'order_number' => [
                'nullable', 'string', 'max:32',
                Rule::unique('students', 'order_number')->ignore($id),
            ],
            'enrolled_on' => ['required', 'date'],
            'previous_school' => ['nullable', 'string', 'max:160'],
            'father_name' => ['nullable', 'string', 'max:160'],
            'mother_name' => ['nullable', 'string', 'max:160'],
            'legal_guardian_name' => ['nullable', 'string', 'max:160'],
            'guardian_relationship' => ['nullable', 'string', 'max:80'],
            'primary_phone' => ['required', 'string', 'max:32'],
            'secondary_phone' => ['nullable', 'string', 'max:32'],
            'parent_email' => ['nullable', 'email', 'max:160'],
            'residential_address' => ['nullable', 'string', 'max:255'],
            'father_profession' => ['nullable', 'string', 'max:120'],
            'mother_profession' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
