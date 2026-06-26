<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Envoie une notification Web Push à toutes les souscriptions d'un utilisateur.
 * Les souscriptions expirées/invalides (404/410) sont supprimées.
 */
class SendWebPush implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  { title, body, url?, tag? }
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $payload,
    ) {}

    public function handle(): void
    {
        $public = config('services.webpush.public_key');
        $private = config('services.webpush.private_key');
        if (empty($public) || empty($private)) {
            return; // VAPID non configuré : on n'envoie rien.
        }

        $subscriptions = PushSubscription::query()->where('user_id', $this->userId)->get();
        if ($subscriptions->isEmpty()) {
            return;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('services.webpush.subject'),
                'publicKey' => $public,
                'privateKey' => $private,
            ],
        ]);

        $body = json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($subscriptions as $sub) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->public_key,
                    'authToken' => $sub->auth_token,
                ]),
                $body,
            );
        }

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                continue;
            }

            $endpoint = (string) $report->getEndpoint();
            if ($report->isSubscriptionExpired()) {
                PushSubscription::query()->where('endpoint', $endpoint)->delete();
            } else {
                Log::warning('Web push échoué.', [
                    'user_id' => $this->userId,
                    'reason' => $report->getReason(),
                ]);
            }
        }
    }
}
