<?php
namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

final class ChannelRateLimiter
{
    public function acquire(string $channel): bool
    {

        $lua = <<<'LUA'
local t = redis.call('TIME')
local now = t[1] * 1000000 + t[2]
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', now - 1000000)
if redis.call('ZCARD', KEYS[1]) >= 100 then return 0 end
redis.call('ZADD', KEYS[1], now, ARGV[1])
redis.call('PEXPIRE', KEYS[1], 2000)
return 1
LUA;
        return (bool) Redis::eval($lua, 1, 'notification:rate:'.$channel, (string) Str::uuid());
    }
}
