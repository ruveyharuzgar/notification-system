<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class DashboardTest extends TestCase
{
    public function test_dashboard_is_available(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}
