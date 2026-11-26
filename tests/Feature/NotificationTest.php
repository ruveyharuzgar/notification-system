<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http,Redis};
use App\Jobs\DeliverNotification;
class NotificationTest extends TestCase {
 use RefreshDatabase;
 private function payload(){return ['recipient'=>'+905551234567','channel'=>'sms','content'=>'Hello','priority'=>'high'];}
 public function test_idempotency_and_cancellation():void{$a=$this->postJson('/api/notifications',$this->payload(),['Idempotency-Key'=>'a'])->assertAccepted()->json();$this->postJson('/api/notifications',$this->payload(),['Idempotency-Key'=>'a'])->assertAccepted()->assertJsonPath('batch_id',$a['batch_id']);$this->assertDatabaseCount('notifications',1);$this->postJson('/api/notifications',array_replace($this->payload(),['content'=>'different']),['Idempotency-Key'=>'a'])->assertConflict();$this->postJson('/api/notifications/'.$a['notifications'][0]['id'].'/cancel')->assertOk();}
 public function test_validation():void{$this->postJson('/api/notifications',array_replace($this->payload(),['content'=>str_repeat('a',161)]),['Idempotency-Key'=>'b'])->assertUnprocessable();}
 public function test_delivery():void{config(['notification.url'=>'https://example.test']);Redis::shouldReceive('eval')->once()->andReturn(1);Http::fake(['*'=>Http::response(['messageId'=>'abc','status'=>'accepted','timestamp'=>'2026-01-01T00:00:00Z'],202)]);$a=$this->postJson('/api/notifications',$this->payload(),['Idempotency-Key'=>'c'])->assertAccepted()->json();$id=$a['notifications'][0]['id'];(new DeliverNotification($id))->handle();$this->assertDatabaseHas('notifications',['id'=>$id,'status'=>'accepted']);Http::assertSentCount(1);}
}
