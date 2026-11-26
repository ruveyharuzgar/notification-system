<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->file(resource_path('dashboard.html'), [
        'Content-Type' => 'text/html; charset=UTF-8',
        'Cache-Control' => 'no-store',
    ]);
});
