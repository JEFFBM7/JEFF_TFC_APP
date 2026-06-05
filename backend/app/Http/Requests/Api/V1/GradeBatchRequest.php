<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Evaluation;
use Illuminate\Foundation\Http\FormRequest;

class GradeBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $evaluation = $this->route('evaluation');
        $maxValue = $evaluation instanceof Evaluation ? (float) $evaluation->max_value : 20;

        return [
            'grades' => ['required', 'array', 'min:1'],
            'grades.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'grades.*.value' => ['nullable', 'numeric', 'min:0', "max:{$maxValue}"],
            'grades.*.absent' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        $evaluation = $this->route('evaluation');
        $maxValue = $evaluation instanceof Evaluation ? (float) $evaluation->max_value : 20;

        return [
            'grades.*.value.min' => "Une note doit être comprise entre 0 et {$maxValue}.",
            'grades.*.value.max' => "Une note doit être comprise entre 0 et {$maxValue}.",
        ];
    }
}
