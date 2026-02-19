<?php

use App\Livewire\Admin\Ach\Batches\Index as AchBatchesIndex;
use App\Livewire\Admin\Ach\Batches\Show as AchBatchesShow;
use App\Livewire\Admin\Ach\Returns\Index as AchReturnsIndex;
use App\Livewire\Admin\ActivityLog;
use App\Livewire\Admin\Clients\Index as ClientsIndex;
use App\Livewire\Admin\Clients\PaymentMethods as ClientPaymentMethods;
use App\Livewire\Admin\Clients\Show as ClientsShow;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Login;
use App\Livewire\Admin\Notifications\Index as NotificationsIndex;
use App\Livewire\Admin\PaymentPlans\Create as PaymentPlansCreate;
use App\Livewire\Admin\PaymentPlans\Index as PaymentPlansIndex;
use App\Livewire\Admin\Payments\Create as PaymentsCreate;
use App\Livewire\Admin\Payments\Index as PaymentsIndex;
use App\Livewire\Admin\PaymentRequests\Index as PaymentRequestsIndex;
use App\Livewire\Admin\RecurringPayments\Create as RecurringPaymentsCreate;
use App\Livewire\Admin\RecurringPayments\Import as RecurringPaymentsImport;
use App\Livewire\Admin\RecurringPayments\Index as RecurringPaymentsIndex;
use App\Livewire\Admin\Users\Index as UsersIndex;
use App\Livewire\Admin\FileUpload\Index as FileUploadIndex;
use App\Livewire\PaymentFlow;
use App\Livewire\PaymentRequestFlow;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payment Gateway Routes
|--------------------------------------------------------------------------
|
| Public payment portal using Livewire for smooth, reactive experience
|
*/

// Livewire Payment Portal (single route)
Route::livewire('/', PaymentFlow::class)->name('payment.start');
Route::livewire('/payment', PaymentFlow::class)->name('payment.flow');

// Account Portal for managing payment plans
Route::get('/account', function () {
    return view('account-portal');
})->name('account.portal');

// Email Payment Request (public, tokenized)
Route::livewire('/pay/{token}', PaymentRequestFlow::class)->name('payment.request');

/*
|--------------------------------------------------------------------------
| Admin Panel Routes
|--------------------------------------------------------------------------
|
| Admin routes for managing payments, payment plans, clients, and users.
| Protected by admin middleware requiring authentication.
|
*/

// Admin login (public)
Route::livewire('/admin/login', Login::class)->name('admin.login');

// Admin logout
Route::post('/admin/logout', function () {
    Auth::logout();
    session()->invalidate();
    session()->regenerateToken();

    return redirect()->route('admin.login');
})->name('admin.logout');

// Protected admin routes
Route::prefix('admin')->middleware(['admin'])->group(function () {
    Route::livewire('/', Dashboard::class)->name('admin.dashboard');
    Route::livewire('/payments', PaymentsIndex::class)->name('admin.payments');
    Route::livewire('/payments/create', PaymentsCreate::class)->name('admin.payments.create');
    Route::livewire('/payment-plans', PaymentPlansIndex::class)->name('admin.payment-plans');
    Route::livewire('/payment-plans/create', PaymentPlansCreate::class)->name('admin.payment-plans.create');
    Route::livewire('/recurring-payments', RecurringPaymentsIndex::class)->name('admin.recurring-payments');
    Route::livewire('/recurring-payments/create', RecurringPaymentsCreate::class)->name('admin.recurring-payments.create');
    Route::livewire('/recurring-payments/import', RecurringPaymentsImport::class)->name('admin.recurring-payments.import');
    Route::livewire('/clients', ClientsIndex::class)->name('admin.clients');
    Route::livewire('/clients/payment-methods', ClientPaymentMethods::class)->name('admin.clients.payment-methods');
    Route::livewire('/clients/{clientId}', ClientsShow::class)->name('admin.clients.show');
    Route::livewire('/users', UsersIndex::class)->name('admin.users');
    Route::livewire('/activity-log', ActivityLog::class)->name('admin.activity-log');

    // ACH Management
    Route::livewire('/ach', AchBatchesIndex::class)->name('admin.ach.batches.index');
    Route::livewire('/ach/batches/{batch}', AchBatchesShow::class)->name('admin.ach.batches.show');
    Route::livewire('/ach/returns', AchReturnsIndex::class)->name('admin.ach.returns.index');
    Route::livewire('/notifications', NotificationsIndex::class)->name('admin.notifications');
    Route::livewire('/payment-requests', PaymentRequestsIndex::class)->name('admin.payment-requests');
    Route::livewire('/file-upload', FileUploadIndex::class)->name('admin.file-upload');

    // Debug client grouping (protected - admin only)
    Route::get('/debug-grouping/{last4}/{name}', function ($last4, $name) {
        $repo = app(\App\Repositories\PaymentRepository::class);
        $client = $repo->getClientByTaxIdAndName($last4, $name);

        if (! $client) {
            return response()->json(['error' => 'Client not found']);
        }

        $clientKey = isset($client['clients']) ? $client['clients'][0]['client_KEY'] : $client['client_KEY'];

        // Get principal (PracticeCS SQL Server)
        $principal = \Illuminate\Support\Facades\DB::connection('sqlsrv')->selectOne('
            SELECT principal__client_KEY FROM Client WHERE client_KEY = ?
        ', [$clientKey]);

        // Get full EIN for the authenticated client (PracticeCS SQL Server)
        $fullEin = \Illuminate\Support\Facades\DB::connection('sqlsrv')->selectOne('
            SELECT federal_tin FROM Client WHERE client_KEY = ?
        ', [$clientKey])->federal_tin;

        // Get all clients with same full EIN (PracticeCS SQL Server)
        $einClients = \Illuminate\Support\Facades\DB::connection('sqlsrv')->select('
            SELECT c.client_KEY, c.client_id, c.description as client_name, c.federal_tin
            FROM Client c
            WHERE c.federal_tin = ?
        ', [$fullEin]);

        return response()->json([
            'login_credentials' => ['last4' => $last4, 'name' => $name],
            'authenticated_client' => $client,
            'client_key' => $clientKey,
            'full_ein' => $fullEin,
            'ein_group_clients' => $einClients,
            'ein_group_count' => count($einClients),
        ]);
    })->name('admin.debug-grouping');
});
