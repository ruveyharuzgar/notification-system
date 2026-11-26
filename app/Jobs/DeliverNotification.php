<?php
namespace App\Jobs;

use App\Models\Notification;
use App\Services\ChannelRateLimiter;
use App\Services\NotificationProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class DeliverNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 20;

    public function __construct(public string $id) {}

    public function handle(): void
    {
        $n = Notification::find($this->id);
        if (!$n || $n->status !== 'pending' || $n->available_at->isFuture()) {
            return;
        }

        if (!app(ChannelRateLimiter::class)->acquire($n->channel)) {
            Notification::whereKey($n->id)->where('status', 'pending')
                ->where('available_at', '<=', now())
                ->update(['dispatch_until' => null, 'available_at' => now()->addSecond()]);
            return;
        }


        if (!Notification::whereKey($n->id)->where('status', 'pending')
            ->where('available_at', '<=', now())->update([
                'status' => 'processing', 'claimed_at' => now(), 'dispatch_until' => null,
                'attempts' => DB::raw('attempts + 1'), 'error' => null,
            ])) {
            return;
        }

        $n->refresh();
        $context = ['notification_id' => $n->id, 'correlation_id' => $n->correlation_id, 'attempt' => $n->attempts, 'channel' => $n->channel];
        Log::info('notification.attempt_started', $context);
        $start = hrtime(true);

        try {
            $response = app(NotificationProvider::class)->send($n);
        } catch (ConnectionException $e) {
            $this->transition($n, 'unknown', ['error' => 'ambiguous_transport_failure']);
            Log::warning('notification.attempt_finished', $context + ['status' => 'unknown']);
            return;
        }

        $code = $response->status();
        $providerId = $response->json('messageId');
        $timestamp = $response->json('timestamp');
        $validTimestamp = is_string($timestamp) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $timestamp) && strtotime($timestamp) !== false;
        if ($code === 202 && $response->json('status') === 'accepted' && is_string($providerId) && trim($providerId) !== '' && strlen($providerId) <= 255 && $validTimestamp) {
            $this->transition($n, 'accepted', ['provider_id' => $providerId]);
        } elseif ($code === 429 && $n->attempts < config('notification.max_attempts')) {
            $retryAfter = $response->header('Retry-After');
            $seconds = ctype_digit((string) $retryAfter) ? (int) $retryAfter : max(0, (strtotime((string) $retryAfter) ?: 0) - now()->timestamp);
            $delay = max(min(86400, $seconds), min(300, 2 ** $n->attempts + random_int(0, 3)));
            $this->transition($n, 'pending', ['available_at' => now()->addSeconds($delay), 'error' => 'provider_rate_limited']);
        } elseif ($code === 429) {
            $this->transition($n, 'failed', ['error' => 'retry_exhausted']);
        } elseif ($response->clientError() && $code !== 408) {
            $this->transition($n, 'failed', ['error' => 'provider_http_'.$code]);
        } else {
            $this->transition($n, 'unknown', ['error' => 'ambiguous_provider_response']);
        }
        Log::info('notification.attempt_finished', $context + ['status' => $n->fresh()->status, 'http_status' => $code, 'provider_latency_ms' => (hrtime(true) - $start) / 1e6]);
    }

    private function transition(Notification $n, string $status, array $extra): void
    {
        Notification::whereKey($n->id)->where('status', 'processing')->where('attempts', $n->attempts)
            ->update($extra + ['status' => $status, 'dispatch_until' => null, 'finished_at' => $status === 'pending' ? null : now()]);
    }
}
