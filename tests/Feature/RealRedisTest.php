<?php
namespace Tests\Feature;

use App\Services\ChannelRateLimiter;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

final class RealRedisTest extends TestCase
{
    public function test_real_redis_sliding_window_and_channel_isolation(): void
    {
        if (!env('RUN_REDIS_TESTS', false)) $this->markTestSkipped('Run in Compose with RUN_REDIS_TESTS=true');
        $a = 'test:'.Str::uuid(); $b = 'test:'.Str::uuid();
        $limiter = app(ChannelRateLimiter::class);
        try {
            $start = hrtime(true);
            for ($i = 0; $i < 100; $i++) $this->assertTrue($limiter->acquire($a));
            $blocked = !$limiter->acquire($a);
            $elapsed = (hrtime(true) - $start) / 1e9;
            if ($elapsed >= 1) $this->markTestSkipped('Redis round trips exceeded one second; burst assertion inconclusive');
            $this->assertTrue($blocked);
            $this->assertTrue($limiter->acquire($b));
            usleep(1_050_000);
            $this->assertTrue($limiter->acquire($a));
        } finally {
            Redis::del('notification:rate:'.$a); Redis::del('notification:rate:'.$b);
        }
    }
}
