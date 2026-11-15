<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeveloperController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UserController;
use App\Modules\Helpdesk\Controllers\PixibotIngestionController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout'])
            ->name('auth.logout');
        Route::get('/me', [AuthController::class, 'me'])
            ->name('auth.me');
    });
});

Route::post('/tickets', [TicketController::class, 'store'])
    ->name('tickets.store');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard.index');

    Route::get('/tickets', [TicketController::class, 'index'])
        ->name('tickets.index');
    Route::get('/tickets/summary', [TicketController::class, 'summary'])
        ->name('tickets.summary');
    Route::get('/tickets/{code}', [TicketController::class, 'show'])
        ->name('tickets.show');
    Route::patch('/tickets/{code}/status', [TicketController::class, 'updateStatus'])
        ->name('tickets.update_status');

    Route::middleware('role:admin')->group(function (): void {
        Route::get('/systems', [SystemController::class, 'index'])
            ->name('systems.index');
        Route::get('/systems/{system}', [SystemController::class, 'show'])
            ->name('systems.show');
        Route::match(['put', 'patch'], '/systems/{system}', [SystemController::class, 'update'])
            ->name('systems.update');
        Route::delete('/systems/{system}', [SystemController::class, 'destroy'])
            ->name('systems.destroy');
        Route::put('/systems/{system}/developers', [SystemController::class, 'syncDevelopers'])
            ->name('systems.sync_developers');

        Route::get('/users', [UserController::class, 'index'])
            ->name('users.index');
        Route::post('/users', [UserController::class, 'store'])
            ->name('users.store');
        Route::get('/users/{user:public_id}', [UserController::class, 'show'])
            ->name('users.show');
        Route::match(['put', 'patch'], '/users/{user:public_id}', [UserController::class, 'update'])
            ->name('users.update');
        Route::delete('/users/{user:public_id}', [UserController::class, 'destroy'])
            ->name('users.destroy');

        Route::get('/developers', [DeveloperController::class, 'index'])
            ->name('developers.index');
    });
});

Route::prefix('v1/pixibot')->group(function (): void {
    Route::post('/tickets', [PixibotIngestionController::class, 'store'])
        ->name('v1.pixibot.tickets.store');
});
