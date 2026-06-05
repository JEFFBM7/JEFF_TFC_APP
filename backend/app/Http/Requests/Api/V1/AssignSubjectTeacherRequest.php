<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class AssignSubjectTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            'school_year_id' => ['required', 'integer', 'exists:school_years,id'],
            'term_id' => ['nullable', 'integer', 'exists:terms,id'],
            'weekly_hours' => ['nullable', 'numeric', 'min:0.25', 'max:99.99'],
        ];
    }
}
