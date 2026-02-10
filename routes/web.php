<?php

use App\Livewire\Admin\Ach\Batches\Index as AchBatchesIndex;
use App\Livewire\Admin\Ach\Batches\Show as AchBatchesShow;
use App\Livewire\Admin\Ach\Files\Index as AchFilesIndex;
use App\Livewire\Admin\Ach\Returns\Index as AchReturnsIndex;
use App\Livewire\Admin\ActivityLog;
use App\Livewire\Admin\Clients\Index as ClientsIndex;
use App\Livewire\Admin\Clients\PaymentMethods as ClientPaymentMethods;
use App\Livewire\Admin\Clients\Show as ClientsShow;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Login;
use App\Livewire\Admin\PaymentPlans\Create as PaymentPlansCreate;
use App\Livewire\Admin\PaymentPlans\Index as PaymentPlansIndex;
use App\Livewire\Admin\Payments\Create as PaymentsCreate;
use App\Livewire\Admin\Payments\Index as PaymentsIndex;
use App\Livewire\Admin\RecurringPayments\Create as RecurringPaymentsCreate;
use App\Livewire\Admin\RecurringPayments\Import as RecurringPaymentsImport;
use App\Livewire\Admin\RecurringPayments\Index as RecurringPaymentsIndex;
use App\Livewire\Admin\Users\Index as UsersIndex;
use App\Livewire\PaymentFlow;
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
Route::get('/', PaymentFlow::class)->name('payment.start');
Route::get('/payment', PaymentFlow::class)->name('payment.flow');

// Account Portal for managing payment plans
Route::get('/account', function () {
    return view('account-portal');
})->name('account.portal');

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
Route::get('/admin/login', Login::class)->name('admin.login');

// Admin logout
Route::post('/admin/logout', function () {
    Auth::logout();
    session()->invalidate();
    session()->regenerateToken();

    return redirect()->route('admin.login');
})->name('admin.logout');

// Protected admin routes
Route::prefix('admin')->middleware(['admin'])->group(function () {
    Route::get('/', Dashboard::class)->name('admin.dashboard');
    Route::get('/payments', PaymentsIndex::class)->name('admin.payments');
    Route::get('/payments/create', PaymentsCreate::class)->name('admin.payments.create');
    Route::get('/payment-plans', PaymentPlansIndex::class)->name('admin.payment-plans');
    Route::get('/payment-plans/create', PaymentPlansCreate::class)->name('admin.payment-plans.create');
    Route::get('/recurring-payments', RecurringPaymentsIndex::class)->name('admin.recurring-payments');
    Route::get('/recurring-payments/create', RecurringPaymentsCreate::class)->name('admin.recurring-payments.create');
    Route::get('/recurring-payments/import', RecurringPaymentsImport::class)->name('admin.recurring-payments.import');
    Route::get('/clients', ClientsIndex::class)->name('admin.clients');
    Route::get('/clients/payment-methods', ClientPaymentMethods::class)->name('admin.clients.payment-methods');
    Route::get('/clients/{clientId}', ClientsShow::class)->name('admin.clients.show');
    Route::get('/users', UsersIndex::class)->name('admin.users');
    Route::get('/activity-log', ActivityLog::class)->name('admin.activity-log');

    // ACH Management
    Route::get('/ach', AchFilesIndex::class)->name('admin.ach.files.index');
    Route::get('/ach/files', AchFilesIndex::class)->name('admin.ach.files');
    Route::get('/ach/batches', AchBatchesIndex::class)->name('admin.ach.batches.index');
    Route::get('/ach/batches/{batch}', AchBatchesShow::class)->name('admin.ach.batches.show');
    Route::get('/ach/returns', AchReturnsIndex::class)->name('admin.ach.returns.index');

    // Debug client grouping (protected - admin only)
    Route::get('/debug-grouping/{last4}/{name}', function ($last4, $name) {
        $repo = app(\App\Repositories\PaymentRepository::class);
        $client = $repo->getClientByTaxIdAndName($last4, $name);

        if (! $client) {
            return response()->json(['error' => 'Client not found']);
        }

        $clientKey = isset($client['clients']) ? $client['clients'][0]['client_KEY'] : $client['client_KEY'];

        // Get principal
        $principal = \Illuminate\Support\Facades\DB::selectOne('
            SELECT principal__client_KEY FROM Client WHERE client_KEY = ?
        ', [$clientKey]);

        // Get full EIN for the authenticated client
        $fullEin = \Illuminate\Support\Facades\DB::selectOne('
            SELECT federal_tin FROM Client WHERE client_KEY = ?
        ', [$clientKey])->federal_tin;

        // Get all clients with same full EIN
        $einClients = \Illuminate\Support\Facades\DB::select('
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
