<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Level;
use App\Models\SchoolOption;
use App\Support\AdminScopeContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClassRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (is_numeric($this->input('level_id'))) {
            AdminScopeContext::assertLevelAllowed($this->user(), (int) $this->input('level_id'));
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $level = $this->selectedLevel();
        $isSecondary = $level?->cycle === Level::CYCLE_SECONDAIRE;
        $schoolOptionId = $isSecondary ? $this->input('school_option_id') : null;
        $option = '';

        if ($isSecondary && is_numeric($schoolOptionId)) {
            $option = (string) SchoolOption::query()
                ->whereKey((int) $schoolOptionId)
                ->value('name');
        }

        $this->merge([
            'section' => trim((string) $this->input('section', '')),
            'school_option_id' => $schoolOptionId,
            'option' => $isSecondary ? $option : '',
        ]);
    }

    public function rules(): array
    {
        $id = $this->route('classroom')?->id;
        $levelId = $this->input('level_id');
        $level = $this->selectedLevel();
        $option = (string) $this->input('option', '');

        return [
            'level_id' => ['required', 'integer', 'exists:levels,id'],
            'section' => [
                'required', 'string', 'max:16',
                Rule::unique('classrooms', 'section')
                    ->where(fn ($q) => $q->where('level_id', $levelId)->where('option', $option))
                    ->ignore($id),
            ],
            'option' => [
                'sometimes',
                'string',
                'max:64',
            ],
            'school_option_id' => [
                Rule::requiredIf($level?->cycle === Level::CYCLE_SECONDAIRE),
                'nullable',
                'integer',
                'exists:school_options,id',
            ],
        ];
    }

    private function selectedLevel(): ?Level
    {
        $levelId = $this->input('level_id');

        if (! is_numeric($levelId)) {
            return null;
        }

        return Level::query()->find((int) $levelId);
    }
}
