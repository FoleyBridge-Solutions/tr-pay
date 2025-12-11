<?php

use App\Livewire\PaymentFlow;
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

// Debug client grouping
Route::get('/debug-grouping/{last4}/{name}', function ($last4, $name) {
    $repo = app(\App\Repositories\PaymentRepository::class);
    $client = $repo->getClientByTaxIdAndName($last4, $name);

    if (!$client) {
        return response()->json(['error' => 'Client not found']);
    }

    $clientKey = isset($client['clients']) ? $client['clients'][0]['client_KEY'] : $client['client_KEY'];

    // Get principal
    $principal = \Illuminate\Support\Facades\DB::selectOne("
        SELECT principal__client_KEY FROM Client WHERE client_KEY = ?
    ", [$clientKey]);

    // Get full EIN for the authenticated client
    $fullEin = \Illuminate\Support\Facades\DB::selectOne("
        SELECT federal_tin FROM Client WHERE client_KEY = ?
    ", [$clientKey])->federal_tin;

    // Get all clients with same full EIN
    $einClients = \Illuminate\Support\Facades\DB::select("
        SELECT c.client_KEY, c.client_id, c.description as client_name, c.federal_tin
        FROM Client c
        WHERE c.federal_tin = ?
    ", [$fullEin]);

    return response()->json([
        'login_credentials' => ['last4' => $last4, 'name' => $name],
        'authenticated_client' => $client,
        'client_key' => $clientKey,
        'full_ein' => $fullEin,
        'ein_group_clients' => $einClients,
        'ein_group_count' => count($einClients)
    ]);
});
