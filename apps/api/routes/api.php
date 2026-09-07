<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AllocationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\BankStatementImportController;
use App\Http\Controllers\Api\BusinessProfileController;
use App\Http\Controllers\Api\CapitalContributionController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\IncomeController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MatchController;
use App\Http\Controllers\Api\OwnerDrawingController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PeriodController;
use App\Http\Controllers\Api\ReconciliationController;
use App\Http\Controllers\Api\ReportingController;
use App\Http\Controllers\Api\TransferController;
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
            Route::get('/business-profile', [BusinessProfileController::class, 'show']);
            Route::put('/business-profile', [BusinessProfileController::class, 'store']);

            Route::get('/accounts', [AccountController::class, 'index']);
            Route::post('/accounts', [AccountController::class, 'store']);

            Route::get('/bank-accounts', [BankAccountController::class, 'index']);
            Route::post('/bank-accounts', [BankAccountController::class, 'store']);
            Route::get('/bank-accounts/{bankAccountId}/transactions', [BankAccountController::class, 'transactions']);
            Route::post('/bank-accounts/{bankAccountId}/import', [BankStatementImportController::class, 'store']);
            Route::get('/bank-accounts/{bankAccountId}/match-suggestions', [MatchController::class, 'suggestions']);
            Route::post('/bank-transactions/{bankTransactionId}/confirm-match', [MatchController::class, 'confirm']);

            Route::get('/bank-accounts/{bankAccountId}/reconciliations', [ReconciliationController::class, 'index']);
            Route::post('/bank-accounts/{bankAccountId}/reconciliations', [ReconciliationController::class, 'store']);
            Route::get('/reconciliations/{reconciliationId}', [ReconciliationController::class, 'show']);
            Route::post('/reconciliations/{reconciliationId}/start-review', [ReconciliationController::class, 'startReview']);
            Route::post('/reconciliations/{reconciliationId}/mark-balanced', [ReconciliationController::class, 'markBalanced']);
            Route::post('/reconciliations/{reconciliationId}/complete', [ReconciliationController::class, 'complete']);
            Route::post('/reconciliations/{reconciliationId}/reopen', [ReconciliationController::class, 'reopen']);

            Route::get('/customers', [CustomerController::class, 'index']);
            Route::post('/customers', [CustomerController::class, 'store']);
            Route::put('/customers/{customerId}', [CustomerController::class, 'update']);

            Route::get('/invoices', [InvoiceController::class, 'index']);
            Route::post('/invoices', [InvoiceController::class, 'store']);
            Route::get('/invoices/{invoiceId}', [InvoiceController::class, 'show']);
            Route::put('/invoices/{invoiceId}', [InvoiceController::class, 'update']);
            Route::delete('/invoices/{invoiceId}', [InvoiceController::class, 'destroy']);
            Route::post('/invoices/{invoiceId}/issue', [InvoiceController::class, 'issue']);
            Route::get('/outstanding-invoices', [AllocationController::class, 'outstandingInvoices']);

            Route::get('/payments', [PaymentController::class, 'index']);
            Route::post('/payments', [PaymentController::class, 'store']);
            Route::get('/payments/{paymentId}', [PaymentController::class, 'show']);
            Route::get('/payments/{paymentId}/allocations', [AllocationController::class, 'index']);
            Route::post('/payments/{paymentId}/allocations', [AllocationController::class, 'store']);
            Route::delete('/payment-allocations/{allocationId}', [AllocationController::class, 'destroy']);

            Route::post('/expenses', [ExpenseController::class, 'store']);
            Route::post('/incomes', [IncomeController::class, 'store']);
            Route::post('/transfers', [TransferController::class, 'store']);
            Route::post('/capital-contributions', [CapitalContributionController::class, 'store']);
            Route::post('/owner-drawings', [OwnerDrawingController::class, 'store']);
            Route::post('/periods/close', [PeriodController::class, 'close']);

            Route::prefix('reports')->group(function (): void {
                Route::get('/trial-balance', [ReportingController::class, 'trialBalance']);
                Route::get('/profit-and-loss', [ReportingController::class, 'profitAndLoss']);
                Route::get('/balance-sheet', [ReportingController::class, 'balanceSheet']);
                Route::get('/general-ledger', [ReportingController::class, 'generalLedger']);
                Route::get('/evidence-index', [ReportingController::class, 'evidenceIndex']);
                Route::get('/aging', [ReportingController::class, 'agingReport']);
            });
        });
    });
});
