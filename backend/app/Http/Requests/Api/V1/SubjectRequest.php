<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ClassRoom;
use App\Support\TeacherSpecialityMatcher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SubjectRequest extends FormRequest
{
    private const EVALUATION_TYPES = ['sur_10', 'sur_20', 'pourcentage'];
    private const STATUSES = ['actif', 'inactif'];

    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach (['name', 'code', 'description', 'evaluation_type', 'status'] as $field) {
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
        $id = $this->route('subject')?->id;

        return [
            'name' => ['required', 'string', 'max:128', Rule::unique('subjects')->ignore($id)],
            'code' => ['nullable', 'string', 'max:32', Rule::unique('subjects')->ignore($id)],
            'description' => ['nullable', 'string', 'max:255'],
            'default_coefficient' => ['sometimes', 'numeric', 'min:0.01', 'max:99.99'],
            'evaluation_type' => ['sometimes', 'string', Rule::in(self::EVALUATION_TYPES)],
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
            'classroom_id' => ['nullable', 'integer', 'exists:classrooms,id', 'required_with:teacher_id'],
            'school_year_id' => ['nullable', 'integer', 'exists:school_years,id', 'required_with:teacher_id'],
            'term_id' => ['nullable', 'integer', 'exists:terms,id'],
            'teacher_id' => ['nullable', 'integer', 'exists:teachers,id'],
            'weekly_hours' => ['nullable', 'numeric', 'min:0.25', 'max:99.99'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('teacher_id') || ! $this->filled('classroom_id')) {
                return;
            }

            $classroom = ClassRoom::query()->with('level')->find($this->integer('classroom_id'));

            if ($classroom !== null && TeacherSpecialityMatcher::isPrimaryOrMaternelClassroom($classroom)) {
                $validator->errors()->add(
                    'teacher_id',
                    'Au primaire et en maternelle, l\'enseignant se définit par classe (fiche Enseignants), pas par cours.',
                );
            }
        });
    }
}
