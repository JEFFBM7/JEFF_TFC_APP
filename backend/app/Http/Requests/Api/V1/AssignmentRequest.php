<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('assignment')?->id;
        $subjectRule = $this->boolean('is_main') ? 'nullable' : 'required';

        return [
            'teacher_id' => [
                'required', 'integer', 'exists:teachers,id',
                Rule::unique('teacher_assignments', 'teacher_id')
                    ->where(fn ($q) => $q
                        ->where('classroom_id', $this->input('classroom_id'))
                        ->where('subject_id', $this->input('subject_id'))
                        ->where('school_year_id', $this->input('school_year_id')))
                    ->ignore($id),
            ],
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            'subject_id' => [$subjectRule, 'integer', 'exists:subjects,id'],
            'school_year_id' => ['required', 'integer', 'exists:school_years,id'],
            'term_id' => ['nullable', 'integer', 'exists:terms,id'],
            'weekly_hours' => ['nullable', 'numeric', 'min:0.25', 'max:99.99'],
            'is_main' => ['sometimes', 'boolean'],
        ];
    }
}
