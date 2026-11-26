<?php
namespace App\Services;

use App\Models\Notification;
use Illuminate\Http\Client\Response;

interface NotificationProvider
{
    public function send(Notification $notification): Response;
}
