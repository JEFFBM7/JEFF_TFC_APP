<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Message> */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'sender_id' => User::factory(),
            'recipient_id' => User::factory(),
            'parent_message_id' => null,
            'subject' => $this->faker->sentence(4),
            'body' => $this->faker->paragraph(),
            'read_at' => null,
            'deleted_by_sender' => false,
            'deleted_by_recipient' => false,
        ];
    }
}
