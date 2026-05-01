<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TermRequest extends FormRequest
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
        $id = $this->route('term')?->id;
        $schoolYearId = $this->input('school_year_id');

        return [
            'school_year_id' => ['required', 'integer', 'exists:school_years,id'],
            'name' => [
                'required', 'string', 'max:64',
                Rule::unique('terms', 'name')
                    ->where(fn ($q) => $q->where('school_year_id', $schoolYearId))
                    ->ignore($id),
            ],
            'position' => [
                'required', 'integer', 'min:1', 'max:6',
                Rule::unique('terms', 'position')
                    ->where(fn ($q) => $q->where('school_year_id', $schoolYearId))
                    ->ignore($id),
            ],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
        ];
    }
}
