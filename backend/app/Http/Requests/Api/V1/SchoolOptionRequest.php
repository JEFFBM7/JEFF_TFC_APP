<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Level;
use App\Models\SchoolOption;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SchoolOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $filiere = $this->input('filiere');
        $cycle = trim((string) $this->input('cycle', ''));
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'abbreviation' => trim((string) $this->input('abbreviation', '')),
            'cycle' => $cycle !== '' ? $cycle : null,
            'filiere' => is_string($filiere) && $filiere !== '' ? $filiere : null,
        ]);
    }

    public function rules(): array
    {
        $id = $this->route('school_option')?->id;

        return [
            'name' => ['required', 'string', 'max:64', Rule::unique('school_options', 'name')->ignore($id)],
            'abbreviation' => ['nullable', 'string', 'max:20'],
            'cycle' => ['nullable', 'string', Rule::in(Level::CYCLES)],
            'filiere' => ['nullable', 'string', Rule::in(SchoolOption::FILIERES)],
        ];
    }
}
