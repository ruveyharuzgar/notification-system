<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('notification_batches',function(Blueprint $t){$t->uuid('id')->primary();$t->string('idempotency_key')->unique();$t->string('request_hash',64);$t->timestamps();});
  Schema::create('notifications',function(Blueprint $t){$t->uuid('id')->primary();$t->foreignUuid('batch_id')->constrained('notification_batches');$t->string('recipient');$t->string('channel',10);$t->text('content');$t->string('priority',10);$t->string('status',20)->default('pending');$t->uuid('correlation_id');$t->unsignedInteger('attempts')->default(0);$t->timestamp('available_at')->index();$t->timestamp('claimed_at')->nullable();$t->timestamp('finished_at')->nullable();$t->string('provider_id')->nullable();$t->string('error')->nullable();$t->timestamps();$t->index(['status','available_at']);$t->index(['channel','created_at']);});
 }
 public function down(): void {Schema::dropIfExists('notifications');Schema::dropIfExists('notification_batches');}
};
