<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class SchoolClassDivisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'count' => ['sometimes', 'integer', 'min:1', 'max:26'],
            'capacity' => ['required', 'integer', 'min:1', 'max:999'],
        ];
    }
}
