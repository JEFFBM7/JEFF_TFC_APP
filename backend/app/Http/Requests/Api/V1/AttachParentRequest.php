<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class AttachParentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'parent_profile_id' => ['required', 'integer', 'exists:parent_profiles,id'],
            'relation' => ['required', 'string', 'in:pere,mere,tuteur'],
        ];
    }
}
