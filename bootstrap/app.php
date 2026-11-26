<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Configuration\Exceptions;
return Application::configure(basePath: dirname(__DIR__))
 ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php')
 ->withMiddleware(function(Middleware $m){ $m->append(\App\Http\Correlation::class); })
 ->withExceptions(function(Exceptions $e){ $e->shouldRenderJsonWhen(fn()=>true); })->create();
