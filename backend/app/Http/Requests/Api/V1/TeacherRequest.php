<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TeacherRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('teacher')?->id;

        return [
            'user_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Enseignant->value),
                Rule::unique('teachers', 'user_id')->ignore($id),
            ],
            'speciality' => ['nullable', 'string', 'max:128'],
            'phone' => ['nullable', 'string', 'max:32'],
        ];
    }
}
