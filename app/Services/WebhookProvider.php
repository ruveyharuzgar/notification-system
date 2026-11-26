<?php
namespace App\Services;

use App\Models\Notification;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class WebhookProvider implements NotificationProvider
{
    public function send(Notification $notification): Response
    {
        return Http::connectTimeout(3)->timeout(8)
            ->withOptions(['allow_redirects' => false])
            ->acceptJson()
            ->withHeaders(['Idempotency-Key' => $notification->id, 'X-Correlation-ID' => $notification->correlation_id])
            ->post(config('notification.url'), [
                'to' => $notification->recipient,
                'channel' => $notification->channel,
                'content' => $notification->content,
            ]);
    }
}
