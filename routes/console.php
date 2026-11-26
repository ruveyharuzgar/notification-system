<?php
use Illuminate\Support\Facades\{Artisan, DB, Log};
use App\Models\Notification as N;
use App\Jobs\DeliverNotification;

Artisan::command('notifications:dispatch', function () {
    $stale = N::where('status', 'processing')->where('claimed_at', '<', now()->subMinutes(2))
        ->update(['status' => 'unknown', 'error' => 'worker_lost', 'finished_at' => now()]);
    if ($stale) Log::warning('notification.stale_claims_recovered', ['count' => $stale]);
    foreach (['high', 'normal', 'low'] as $priority) {
        $lease = now()->addMinutes(5)->startOfSecond();
        $ids = DB::transaction(function () use ($priority, $lease) {
            $query = N::where('status', 'pending')->where('priority', $priority)
                ->where('available_at', '<=', now())
                ->where(fn ($q) => $q->whereNull('dispatch_until')->orWhere('dispatch_until', '<=', now()))
                ->orderBy('available_at')->orderBy('id')->limit(300);
            $ids = (DB::getDriverName() === 'mysql' ? $query->lock('for update skip locked') : $query->lockForUpdate())->pluck('id');
            N::whereIn('id', $ids)->update(['dispatch_until' => $lease]);
            return $ids;
        }, 3);
        foreach ($ids as $id) {
            try { DeliverNotification::dispatch($id)->onQueue($priority); }
            catch (\Throwable $e) {
                N::whereKey($id)->where('status', 'pending')->where('dispatch_until', $lease)->update(['dispatch_until' => null]);
                report($e);
            }
        }
    }
});
