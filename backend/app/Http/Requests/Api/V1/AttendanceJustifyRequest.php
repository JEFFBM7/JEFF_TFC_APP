<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceJustifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'justified' => ['required', 'boolean'],
            'justification' => ['nullable', 'string', 'max:500'],
        ];
    }
}
