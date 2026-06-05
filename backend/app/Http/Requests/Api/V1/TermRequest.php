<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Term;
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
        $id          = $this->route('term')?->id;
        $schoolYearId = $this->input('school_year_id');
        $cycle        = $this->input('applicable_cycle', Term::CYCLE_PRIMAIRE);

        return [
            'school_year_id' => ['required', 'integer', 'exists:school_years,id'],
            'term_type' => ['sometimes', 'string', Rule::in(Term::TYPES)],
            'applicable_cycle' => ['sometimes', 'string', Rule::in(Term::CYCLES)],
            'name' => [
                'required', 'string', 'max:64',
                Rule::unique('terms', 'name')
                    ->where(fn ($q) => $q->where('school_year_id', $schoolYearId)
                                        ->where('applicable_cycle', $cycle))
                    ->ignore($id),
            ],
            'position' => [
                'required', 'integer', 'min:1', 'max:5',
                Rule::unique('terms', 'position')
                    ->where(fn ($q) => $q->where('school_year_id', $schoolYearId)
                                        ->where('applicable_cycle', $cycle))
                    ->ignore($id),
            ],
            'starts_on' => ['required', 'date'],
            'ends_on'   => ['required', 'date', 'after:starts_on'],
        ];
    }
}
