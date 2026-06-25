<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ClassRoom;
use App\Models\Evaluation;
use App\Models\Period;
use App\Models\Term;
use App\Support\SchoolYearContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EvaluationRequest extends FormRequest
{
    /**
     * Verrouille l'année archivée avant toute validation : une année close
     * doit répondre 423 (Locked) même si la charge utile est par ailleurs invalide.
     */
    protected function prepareForValidation(): void
    {
        $periodId = $this->integer('period_id');
        if ($periodId <= 0) {
            return;
        }

        $period = Period::query()->with('term')->find($periodId);
        if ($period?->term !== null) {
            SchoolYearContext::assertTermNotArchived($period->term);
        }
    }

    public function authorize(): bool
    {
        /** @var Evaluation|null $evaluation */
        $evaluation = $this->route('evaluation');

        if ($evaluation instanceof Evaluation && $this->isMethod('PUT')) {
            return Evaluation::roleCanManageType($this->user()?->role, $evaluation->type);
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'term_id' => ['nullable', 'integer', 'exists:terms,id'],
            'period_id' => [
                'required',
                'integer',
                'exists:periods,id',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $this->filled('term_id')) {
                        return;
                    }

                    $matches = Period::query()
                        ->whereKey((int) $value)
                        ->where('term_id', $this->integer('term_id'))
                        ->exists();

                    if (! $matches) {
                        $fail('La période sélectionnée ne correspond pas au trimestre.');
                        return;
                    }

                    $period = Period::query()->with('term')->find((int) $value);
                    $classroom = ClassRoom::query()->with('level')->find($this->integer('classroom_id'));

                    if ($period?->term !== null && $classroom !== null) {
                        $expectedCycle = Term::applicableCycleForLevelCycle($classroom->level?->cycle);
                        if ($period->term->applicable_cycle !== $expectedCycle) {
                            $fail('Le trimestre sélectionné ne correspond pas au cycle de la classe (Maternelle/Primaire ou Secondaire/CTEB).');
                        }
                    }
                },
            ],
            'teacher_id' => ['nullable', 'integer', 'exists:teachers,id'],
            'name' => ['required', 'string', 'max:128'],
            'type' => ['required', Rule::in($this->allowedTypesForUser())],
            'held_on' => ['required', 'date'],
            'max_value' => ['sometimes', 'numeric', 'min:1', 'max:100'],
        ];
    }

    /** @return list<string> */
    private function allowedTypesForUser(): array
    {
        return Evaluation::typesForRole($this->user()?->role);
    }
}
