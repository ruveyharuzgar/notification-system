<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\NotificationController as C;
Route::post('notifications',[C::class,'create']);
Route::post('notifications/batch',[C::class,'batch']);
Route::get('notifications',[C::class,'index']);
Route::get('notifications/{id}',[C::class,'show']);
Route::get('batches/{id}',[C::class,'showBatch']);
Route::post('notifications/{id}/cancel',[C::class,'cancel']);
Route::get('metrics',[C::class,'metrics']);
Route::get('health',[C::class,'health']);
