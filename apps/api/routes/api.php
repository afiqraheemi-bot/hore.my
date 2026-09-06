<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\IncomeController;
use App\Http\Controllers\Api\PeriodController;
use App\Http\Controllers\Api\ReportingController;
use Illuminate\Support\Facades\Route;

/**
 * ADR-0008: REST/JSON, versioned under `/v1` (the framework already
 * prefixes every route in this file with `/api`, so this yields
 * `/api/v1/...`). Sanctum's `EnsureFrontendRequestsAreStateful`
 * middleware is applied to the whole `api` group globally via
 * `bootstrap/app.php`'s `statefulApi()` — it is not repeated here.
 */
Route::prefix('v1')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::middleware(['tenant.resolved'])->group(function (): void {
            Route::get('/accounts', [AccountController::class, 'index']);
            Route::post('/accounts', [AccountController::class, 'store']);

            Route::post('/expenses', [ExpenseController::class, 'store']);
            Route::post('/incomes', [IncomeController::class, 'store']);
            Route::post('/periods/close', [PeriodController::class, 'close']);

            Route::prefix('reports')->group(function (): void {
                Route::get('/trial-balance', [ReportingController::class, 'trialBalance']);
                Route::get('/profit-and-loss', [ReportingController::class, 'profitAndLoss']);
                Route::get('/balance-sheet', [ReportingController::class, 'balanceSheet']);
                Route::get('/general-ledger', [ReportingController::class, 'generalLedger']);
                Route::get('/evidence-index', [ReportingController::class, 'evidenceIndex']);
            });
        });
    });
});
