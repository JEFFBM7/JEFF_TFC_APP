<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Period;
use App\Models\Term;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PeriodRequest extends FormRequest
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
        $id = $this->route('period')?->id;
        $termId = $this->input('term_id');

        return [
            'term_id' => ['required', 'integer', 'exists:terms,id'],
            'name' => [
                'required', 'string', 'max:64',
                Rule::unique('periods', 'name')
                    ->where(fn ($q) => $q->where('term_id', $termId))
                    ->ignore($id),
            ],
            'position' => [
                'required', 'integer', 'min:1', 'max:10',
            ],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('term_id') || $validator->errors()->has('position')) {
                return;
            }

            $term = Term::query()->find($this->integer('term_id'));
            if ($term === null) {
                return;
            }

            $position = $this->integer('position');
            $allowedPositions = Period::positionsForTerm($term);

            if (! in_array($position, $allowedPositions, true)) {
                $validator->errors()->add(
                    'position',
                    sprintf(
                        'Ce %s accepte uniquement les périodes %d et %d.',
                        strtolower($term->typeLabel()),
                        $allowedPositions[0],
                        $allowedPositions[1],
                    ),
                );

                return;
            }

            $id = $this->route('period')?->id;
            $alreadyUsed = Period::query()
                ->where('position', $position)
                ->when($id, fn ($query) => $query->whereKeyNot($id))
                ->whereHas('term', fn ($query) => $query->where('school_year_id', $term->school_year_id))
                ->exists();

            if ($alreadyUsed) {
                $validator->errors()->add(
                    'position',
                    'Cette période existe déjà pour cette année scolaire.',
                );
            }
        });
    }
}
