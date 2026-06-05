<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ParentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('parent')?->id;

        return [
            'user_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Parent->value),
                Rule::unique('parent_profiles', 'user_id')->ignore($id),
            ],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
        ];
    }
}
