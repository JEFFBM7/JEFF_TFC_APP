<?php

namespace App\Http\Requests\Api\V1;

use App\Services\MessageRecipientService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class MessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipient_id' => ['required', 'integer', 'exists:users,id'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'parent_message_id' => ['nullable', 'integer', 'exists:messages,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $recipientId = (int) $this->input('recipient_id');
            if ($this->user() && $recipientId === $this->user()->id) {
                $v->errors()->add('recipient_id', 'Vous ne pouvez pas vous envoyer un message à vous-même.');
            }

            if (
                $this->user()
                && ! app(MessageRecipientService::class)->canSendTo($this->user(), $recipientId)
            ) {
                $v->errors()->add('recipient_id', 'Ce destinataire n’est pas autorisé pour votre profil.');
            }
        });
    }
}
