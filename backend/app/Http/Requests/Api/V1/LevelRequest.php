<?php

namespace App\Http\Requests\Api\V1;

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
            'order' => ['sometimes', 'integer', 'min:0', 'max:255'],
        ];
    }
}
