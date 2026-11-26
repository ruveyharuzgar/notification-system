<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\{TestCase,RefreshDatabase};
use Illuminate\Support\Facades\{Http,Redis};
use App\Jobs\DeliverNotification;
class EdgeCasesTest extends TestCase {
    use RefreshDatabase;
    private function payload(): array { return ['recipient'=>'+905551234567','channel'=>'sms','content'=>'Hello','priority'=>'high']; }
    private function create(array $extra=[]): string {
        return $this->postJson('/api/notifications',array_replace($this->payload(),$extra),['Idempotency-Key'=>'edge'])->assertAccepted()->json('notifications.0.id');
    }
    public function test_batch_limit_and_lookup(): void {
        $this->postJson('/api/notifications/batch',['notifications'=>array_fill(0,1001,$this->payload())],['Idempotency-Key'=>'too-many'])->assertUnprocessable();
        $batch=$this->postJson('/api/notifications/batch',['notifications'=>array_fill(0,1000,$this->payload())],['Idempotency-Key'=>'batch'])->assertAccepted()->json('batch_id');
        $this->getJson('/api/batches/'.$batch)->assertOk()->assertJsonPath('total',1000);
        $this->getJson('/api/notifications?channel=sms&status=pending&per_page=10')->assertOk()->assertJsonCount(10,'data');
    }
    public function test_scheduled_and_cancelled_are_not_sent(): void {
        Http::fake(); $id=$this->create(['scheduled_at'=>now()->addHour()->toIso8601String()]);
        (new DeliverNotification($id))->handle();
        $this->postJson('/api/notifications/'.$id.'/cancel')->assertOk();
        (new DeliverNotification($id))->handle(); Http::assertNothingSent();
    }
    public function test_rate_limit_does_not_consume_attempt(): void {
        Http::fake(); Redis::shouldReceive('eval')->once()->andReturn(0);
        $id=$this->create(); (new DeliverNotification($id))->handle();
        $this->assertDatabaseHas('notifications',['id'=>$id,'status'=>'pending','attempts'=>0]); Http::assertNothingSent();
    }
    public function test_retry_and_duplicate_job(): void {
        config(['notification.url'=>'https://example.test']); Redis::shouldReceive('eval')->twice()->andReturn(1);
        Http::fakeSequence()->push([],429,['Retry-After'=>'10'])->push(['messageId'=>'abc','status'=>'accepted','timestamp'=>'2026-01-01T00:00:00Z'],202);
        $id=$this->create(); $job=new DeliverNotification($id); $job->handle();
        $this->assertDatabaseHas('notifications',['id'=>$id,'status'=>'pending','attempts'=>1]);
        $this->travel(20)->seconds(); $job->handle(); $job->handle();
        $this->assertDatabaseHas('notifications',['id'=>$id,'status'=>'accepted','attempts'=>2]); Http::assertSentCount(2);
    }
    public function test_ambiguous_failure_is_not_resent(): void {
        config(['notification.url'=>'https://example.test']); Redis::shouldReceive('eval')->once()->andReturn(1);
        Http::fake(['*'=>Http::response([],503)]); $id=$this->create();
        $job=new DeliverNotification($id); $job->handle(); $job->handle();
        $this->assertDatabaseHas('notifications',['id'=>$id,'status'=>'unknown']); Http::assertSentCount(1);
    }
}
