<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TimetableSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'school_year_id' => ['required', 'integer', 'exists:school_years,id'],
            'day_of_week' => ['required', 'integer', 'between:1,6'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'room' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $query = \App\Models\TimetableSlot::query()
                ->where('classroom_id', $this->integer('classroom_id'))
                ->where('school_year_id', $this->integer('school_year_id'))
                ->where('day_of_week', $this->integer('day_of_week'))
                ->where(function ($q): void {
                    $start = $this->string('starts_at')->value();
                    $end = $this->string('ends_at')->value();
                    $q->where(function ($q) use ($start, $end): void {
                        $q->where('starts_at', '<', $end)
                            ->where('ends_at', '>', $start);
                    });
                });

            $existing = $this->route('timetable_slot');
            if ($existing) {
                $query->where('id', '!=', $existing->id);
            }

            if ($query->exists()) {
                $v->errors()->add('starts_at', 'Ce créneau chevauche un autre cours déjà planifié dans cette classe.');
            }
        });
    }
}
