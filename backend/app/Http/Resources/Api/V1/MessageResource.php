<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sender_id' => $this->sender_id,
            'recipient_id' => $this->recipient_id,
            'parent_message_id' => $this->parent_message_id,
            'subject' => $this->subject,
            'body' => $this->body,
            'read_at' => $this->read_at,
            'is_read' => $this->is_read,
            'is_announcement' => (bool) $this->is_announcement,
            'broadcast_id' => $this->broadcast_id,
            'sender' => $this->whenLoaded('sender', fn () => [
                'id' => $this->sender->id,
                'name' => $this->sender->name,
                'email' => $this->sender->email,
                'role' => $this->sender->role,
            ]),
            'recipient' => $this->whenLoaded('recipient', fn () => [
                'id' => $this->recipient->id,
                'name' => $this->recipient->name,
                'email' => $this->recipient->email,
                'role' => $this->recipient->role,
            ]),
            'replies' => MessageResource::collection($this->whenLoaded('replies')),
            'replies_count' => $this->when(
                isset($this->resource->replies_count),
                fn () => $this->resource->replies_count,
            ),
            'recipients_count' => $this->when(
                isset($this->resource->recipients_count),
                fn () => (int) $this->resource->recipients_count,
            ),
            'broadcast_recipients' => $this->when(
                $this->resource->getAttribute('broadcast_recipients') !== null,
                fn () => $this->resource->getAttribute('broadcast_recipients'),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
