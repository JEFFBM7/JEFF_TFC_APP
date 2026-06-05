<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Level;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LevelRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('level')?->id;

        return [
            'name' => ['required', 'string', 'max:64', Rule::unique('levels')->ignore($id)],
            'abbreviation' => ['nullable', 'string', 'max:16'],
            'cycle' => ['required', 'string', Rule::in(Level::CYCLES)],
            'order' => ['sometimes', 'integer', 'min:0', 'max:255'],
            'has_options' => ['sometimes', 'boolean'],
        ];
    }
}
