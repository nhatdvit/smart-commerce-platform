<?php

declare(strict_types=1);

use App\Shared\Presentation\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * Orchestration probes. Outside api/v1 on purpose: a load balancer or
 * Kubernetes should not have to know the business API version to find out
 * whether this instance is alive (CLAUDE.md section 14).
 */

Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');
