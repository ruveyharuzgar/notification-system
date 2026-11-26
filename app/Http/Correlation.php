<?php
namespace App\Http;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
class Correlation {
 public function handle($request, Closure $next) {
  $id=(string) Str::uuid(); $request->attributes->set('correlation_id',$id);
  Log::withContext(['correlation_id'=>$id]);
  $response=$next($request); $response->headers->set('X-Correlation-ID',$id); return $response;
 }
}
