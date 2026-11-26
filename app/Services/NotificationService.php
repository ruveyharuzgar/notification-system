<?php
namespace App\Services;

use App\Models\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class NotificationService
{
    public function create(string|null $key, array $items, string $correlationId): array
    {
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:200', 'regex:/^[!-~]+$/D']])->validate();
        $data = Validator::make(['items' => $items], [
            'items' => 'required|array|list|min:1|max:1000',
            'items.*' => 'required|array',
            'items.*.recipient' => 'required|string|max:255',
            'items.*.channel' => 'required|in:sms,email,push',
            'items.*.content' => 'required|string|max:10000',
            'items.*.priority' => 'required|in:high,normal,low',
            'items.*.scheduled_at' => 'nullable|date',
        ])->validate()['items'];
        $data = array_map(function (array $v): array {
            $v = array_intersect_key($v, array_flip(['recipient', 'channel', 'content', 'priority', 'scheduled_at']));
            $v['scheduled_at'] = isset($v['scheduled_at']) ? Carbon::parse($v['scheduled_at'])->utc()->toIso8601String() : null;
            ksort($v);
            return $v;
        }, $data);
        foreach ($data as $i => $v) {
            $valid = match ($v['channel']) {
                'sms' => preg_match('/^\+[1-9][0-9]{7,14}$/D', $v['recipient']) && mb_strlen($v['content']) <= 160,
                'email' => filter_var($v['recipient'], FILTER_VALIDATE_EMAIL),
                'push' => mb_strlen($v['content']) <= 4096,
            };
            if (!$valid) {
                throw ValidationException::withMessages(["items.$i" => 'Invalid recipient or content length']);
            }
        }

        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $id = (string) Str::uuid();
        $batch = DB::transaction(function () use ($key, $hash, $id, $data, $correlationId) {
            $time = now();
            $inserted = DB::table('notification_batches')->insertOrIgnore([
                'id' => $id, 'idempotency_key' => $key, 'request_hash' => $hash, 'created_at' => $time, 'updated_at' => $time,
            ]);
            $batch = DB::table('notification_batches')->where('idempotency_key', $key)->lockForUpdate()->first();
            abort_unless($batch && $batch->request_hash === $hash, 409, 'Idempotency key reused with different payload');
            if ($inserted) {
                $rows = [];
                foreach ($data as $v) {
                    $rows[] = [
                        'id' => (string) Str::uuid(), 'batch_id' => $id, 'recipient' => $v['recipient'],
                        'channel' => $v['channel'], 'content' => $v['content'], 'priority' => $v['priority'],
                        'status' => 'pending', 'correlation_id' => $correlationId,
                        'available_at' => $v['scheduled_at'] ? Carbon::parse($v['scheduled_at'])->utc() : $time,
                        'created_at' => $time, 'updated_at' => $time,
                    ];
                }
                foreach (array_chunk($rows, 100) as $chunk) {
                    Notification::insert($chunk);
                }
            }
            return $batch;
        }, 3);
        return ['batch_id' => $batch->id, 'notifications' => Notification::where('batch_id', $batch->id)->orderBy('id')->get(['id', 'status'])];
    }
}
