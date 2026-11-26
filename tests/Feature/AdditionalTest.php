<?php
namespace Tests\Feature;

use App\Jobs\DeliverNotification;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

final class AdditionalTest extends TestCase
{
    use RefreshDatabase;
    private function create(string $key = 'additional', array $overrides = []): string
    {
        return $this->postJson('/api/notifications', array_replace([
            'recipient' => '+905551234567', 'channel' => 'sms', 'content' => 'Hello', 'priority' => 'high',
        ], $overrides), ['Idempotency-Key' => $key])->assertAccepted()->json('notifications.0.id');
    }
    public function test_dispatch_queues_by_priority_and_skips_leased_records(): void
    {
        Queue::fake();
        foreach (['high', 'normal', 'low'] as $priority) $this->create($priority, ['priority' => $priority]);
        $this->artisan('notifications:dispatch')->assertSuccessful();
        $this->artisan('notifications:dispatch')->assertSuccessful();
        foreach (['high', 'normal', 'low'] as $priority) Queue::assertPushedOn($priority, DeliverNotification::class);
        Queue::assertPushed(DeliverNotification::class, 3);
    }
    public function test_stale_claim_is_recovered_without_resend(): void
    {
        Queue::fake(); $id = $this->create();
        Notification::whereKey($id)->update(['status' => 'processing', 'claimed_at' => now()->subMinutes(3)]);
        $this->artisan('notifications:dispatch')->assertSuccessful();
        $this->assertDatabaseHas('notifications', ['id' => $id, 'status' => 'unknown']);
        Queue::assertNothingPushed();
    }
    public function test_retry_exhaustion_and_cancel_conflict(): void
    {
        config(['notification.url' => 'https://example.test']);
        Redis::shouldReceive('eval')->times(5)->andReturn(1);
        Http::fake(['*' => Http::response([], 429)]);
        $id = $this->create();
        for ($i = 0; $i < 5; $i++) {
            (new DeliverNotification($id))->handle();
            $this->travel(5)->minutes();
        }
        $this->assertDatabaseHas('notifications', ['id' => $id, 'status' => 'failed', 'attempts' => 5]);
        Http::assertSentCount(5);
        $this->postJson('/api/notifications/'.$id.'/cancel')->assertConflict();
    }
    public function test_health_and_metrics(): void
    {
        Redis::shouldReceive('ping')->once()->andReturn('PONG');
        $this->getJson('/api/health')->assertOk()->assertJsonPath('checks.redis', 'ok');
        $id = $this->create();
        Notification::whereKey($id)->update(['status' => 'accepted', 'finished_at' => now()]);
        $this->getJson('/api/metrics')->assertOk()->assertJsonPath('success_rate', 1)->assertJsonPath('window_hours', 24);
    }
    public function test_dates_and_case_sensitive_keys(): void
    {
        $a = $this->create('Case'); $b = $this->create('case');
        $this->assertNotSame($a, $b);
        $this->getJson('/api/notifications?from='.now()->toDateString().'&to='.now()->toDateString())->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/notifications?from=2026-01-02&to=2026-01-01')->assertUnprocessable();
    }
    public function test_malformed_provider_success_is_unknown(): void
    {
        config(['notification.url' => 'https://example.test']);
        Redis::shouldReceive('eval')->once()->andReturn(1);
        Http::fake(['*' => Http::response(['status' => 'accepted', 'messageId' => 'abc'], 202)]);
        $id = $this->create(); (new DeliverNotification($id))->handle();
        $this->assertDatabaseHas('notifications', ['id' => $id, 'status' => 'unknown']);
    }
}
