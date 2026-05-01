<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClassRoomRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('classroom')?->id;
        $levelId = $this->input('level_id');

        return [
            'level_id' => ['required', 'integer', 'exists:levels,id'],
            'section' => [
                'required', 'string', 'max:16',
                Rule::unique('classrooms', 'section')
                    ->where(fn ($q) => $q->where('level_id', $levelId))
                    ->ignore($id),
            ],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }
}
