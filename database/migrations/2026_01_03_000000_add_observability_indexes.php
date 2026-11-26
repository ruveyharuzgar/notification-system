<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'mysql') {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE notification_batches MODIFY idempotency_key VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL');
        }
        Schema::table('notifications', function (Blueprint $t) {
            $t->index(['status','claimed_at']);
            $t->index(['finished_at','status']);
            $t->index(['created_at','id']);
            $t->index(['status','created_at','id']);
        });
    }
    public function down(): void {
        Schema::table('notifications', function (Blueprint $t) {
            $t->dropIndex(['status','claimed_at']);
            $t->dropIndex(['finished_at','status']);
            $t->dropIndex(['created_at','id']);
            $t->dropIndex(['status','created_at','id']);
        });
    }
};
