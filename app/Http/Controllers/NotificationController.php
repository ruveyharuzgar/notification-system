<?php
namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

final class NotificationController
{
    public function create(Request $request, NotificationService $service)
    {
        return response()->json($service->create($request->header('Idempotency-Key'), [$request->all()], $request->attributes->get('correlation_id')), 202);
    }

    public function batch(Request $request, NotificationService $service)
    {
        $data = $request->validate(['notifications' => 'required|array|list|min:1|max:1000']);
        return response()->json($service->create($request->header('Idempotency-Key'), $data['notifications'], $request->attributes->get('correlation_id')), 202);
    }

    public function show(string $id)
    {
        return Notification::findOrFail($id);
    }

    public function showBatch(string $id)
    {
        abort_unless(DB::table('notification_batches')->where('id', $id)->exists(), 404);
        return Notification::where('batch_id', $id)->orderBy('id')->paginate(100);
    }

    public function index(Request $request)
    {
        $v = $request->validate([
            'status' => 'sometimes|in:pending,processing,accepted,failed,cancelled,unknown',
            'channel' => 'sometimes|in:sms,email,push', 'from' => 'sometimes|date',
            'to' => 'sometimes|date', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        $query = Notification::query();
        foreach (['status', 'channel'] as $field) {
            if (isset($v[$field])) $query->where($field, $v[$field]);
        }
        $from = isset($v['from']) ? Carbon::parse($v['from'])->utc() : null;
        $to = isset($v['to']) ? Carbon::parse($v['to'])->utc() : null;
        if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v['to'])) $to->endOfDay();
        if ($from && $to && $from->gt($to)) throw \Illuminate\Validation\ValidationException::withMessages(['to' => 'Must not be before from']);
        if ($from) $query->where('created_at', '>=', $from);
        if ($to) $query->where('created_at', '<=', $to);
        return $query->orderByDesc('created_at')->orderBy('id')->paginate($v['per_page'] ?? 25);
    }

    public function cancel(string $id)
    {
        Notification::findOrFail($id);
        abort_unless(Notification::whereKey($id)->where('status', 'pending')->update(['status' => 'cancelled', 'finished_at' => now(), 'dispatch_until' => null]), 409, 'Not pending');
        return response()->json(['status' => 'cancelled']);
    }

    public function metrics()
    {
        $since = now()->subDay();
        $counts = Notification::where('finished_at', '>=', $since)->groupBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status');
        $accepted = (int) ($counts['accepted'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);
        $expression = DB::getDriverName() === 'sqlite' ? 'AVG((julianday(finished_at)-julianday(created_at))*86400000)' : 'AVG(TIMESTAMPDIFF(MICROSECOND, created_at, finished_at))/1000';
        $latency = Notification::where('status', 'accepted')->where('finished_at', '>=', $since)->selectRaw($expression.' as ms')->first()->ms;
        return [
            'window_hours' => 24, 'terminal_counts' => $counts,
            'queue_depth' => collect(['high', 'normal', 'low'])->mapWithKeys(fn ($p) => [$p => Queue::size($p)]),
            'due_pending' => Notification::where('status', 'pending')->where('available_at', '<=', now())->count(),
            'success_rate' => $accepted + $failed ? $accepted / ($accepted + $failed) : null,
            'failure_rate' => $accepted + $failed ? $failed / ($accepted + $failed) : null,
            'average_latency_ms' => $latency === null ? null : (float) $latency,
        ];
    }

    public function health()
    {
        $checks = [];
        try { DB::select('SELECT 1'); $checks['database'] = 'ok'; } catch (\Throwable) { $checks['database'] = 'error'; }
        try { Redis::ping(); $checks['redis'] = 'ok'; } catch (\Throwable) { $checks['redis'] = 'error'; }
        $ok = !in_array('error', $checks, true);
        return response()->json(['status' => $ok ? 'ok' : 'unhealthy', 'checks' => $checks], $ok ? 200 : 503);
    }
}
