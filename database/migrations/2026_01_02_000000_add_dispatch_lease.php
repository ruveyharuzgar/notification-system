<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('notifications', function(Blueprint $t) {
            $t->timestamp('dispatch_until')->nullable();
            $t->index(['status','priority','available_at','dispatch_until'], 'dispatch_scan');
        });
    }
    public function down(): void {
        Schema::table('notifications', function(Blueprint $t) {
            $t->dropIndex('dispatch_scan'); $t->dropColumn('dispatch_until');
        });
    }
};
