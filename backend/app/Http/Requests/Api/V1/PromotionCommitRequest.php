<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class PromotionCommitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to_year_id' => ['required', 'integer', 'exists:school_years,id'],
            'decisions' => ['required', 'array', 'min:1'],
            'decisions.*.enrollment_id' => ['required', 'integer', 'exists:enrollments,id'],
            'decisions.*.decision' => ['required', 'string', 'in:promu,redouble,diplome,skip'],
            'decisions.*.target_classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'],
        ];
    }
}
