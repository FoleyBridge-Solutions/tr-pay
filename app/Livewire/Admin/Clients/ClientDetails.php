<?php

// app/Livewire/Admin/Clients/ClientDetails.php

namespace App\Livewire\Admin\Clients;

use App\Mail\PaymentRequestMail;
use App\Models\Ach\AchEntry;
use App\Models\AdminActivity;
use App\Models\Customer;
use App\Models\CustomerPaymentMethod;
use App\Models\Payment;
use App\Models\PaymentPlan;
use App\Models\PaymentRequest;
use App\Models\RecurringPayment;
use App\Repositories\PaymentRepository;
use App\Services\CustomerPaymentMethodService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Lazy-loaded client details component.
 *
 * Displays detailed information for a single client from PracticeCS,
 * including payment methods, invoices, recurring payments, plans, and history.
 */
#[Lazy]
class ClientDetails extends Component
{
    public string $clientId;

    public ?array $client = null;

    public float $balance = 0;

    public array $openInvoices = [];

    public Collection $paymentMethods;

    public Collection $payments;

    public Collection $recurringPayments;

    public Collection $paymentPlans;

    public bool $loading = true;

    public bool $notFound = false;

    public bool $retrying = false;

    public bool $editingClientId = false;

    public string $newClientId = '';

    // ==================== Payment Request Modal ====================
    public string $paymentRequestEmail = '';

    public float $paymentRequestAmount = 0;

    public string $paymentRequestMessage = '';

    public array $paymentRequestInvoices = [];

    /**
     * Available email addresses for the client from PracticeCS.
     *
     * @var array<int, string>
     */
    public array $clientEmails = [];

    /**
     * The ID of the recurring payment being assigned a method.
     */
    public ?int $assigningRecurringPaymentId = null;

    /**
     * Pre-formatted data for the assign payment method modal.
     * Uses the Alpine $wire reactive getter pattern to bypass Flux modal morph issues.
     *
     * @var array{recurring_id: int, recurring_amount: string, recurring_frequency: string, methods: array}
     */
    public array $assignModalDetails = [];

    protected PaymentRepository $paymentRepo;

    protected CustomerPaymentMethodService $paymentMethodService;

    public function boot(PaymentRepository $paymentRepo, CustomerPaymentMethodService $paymentMethodService): void
    {
        $this->paymentRepo = $paymentRepo;
        $this->paymentMethodService = $paymentMethodService;
    }

    /**
     * Mount the component with the client ID passed from the parent.
     */
    public function mount(string $clientId): void
    {
        $this->clientId = $clientId;
        $this->paymentMethods = collect();
        $this->payments = collect();
        $this->recurringPayments = collect();
        $this->paymentPlans = collect();
        $this->loadClient();
    }

    /**
     * Skeleton placeholder shown while component loads.
     */
    public function placeholder(): string
    {
        return <<<'HTML'
        <div>
            <flux:skeleton.group animate="shimmer">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {{-- Left Column Skeleton --}}
                    <div class="space-y-6">
                        {{-- Client Info Card Skeleton --}}
                        <flux:card>
                            <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                                <flux:skeleton class="h-5 w-36 rounded" />
                            </div>
                            <div class="p-4 space-y-4">
                                <div class="space-y-1">
                                    <flux:skeleton.line class="w-20" />
                                    <flux:skeleton class="h-5 w-40 rounded" />
                                </div>
                                <div class="space-y-1">
                                    <flux:skeleton.line class="w-16" />
                                    <flux:skeleton class="h-5 w-24 rounded" />
                                </div>
                                <div class="space-y-1">
                                    <flux:skeleton.line class="w-24" />
                                    <flux:skeleton class="h-5 w-20 rounded" />
                                </div>
                                <div class="pt-2 border-t border-zinc-200 dark:border-zinc-700 space-y-1">
                                    <flux:skeleton.line class="w-24" />
                                    <flux:skeleton class="h-7 w-28 rounded" />
                                </div>
                            </div>
                        </flux:card>

                        {{-- Open Invoices Card Skeleton --}}
                        <flux:card>
                            <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                                <flux:skeleton class="h-5 w-32 rounded" />
                            </div>
                            <div class="p-4 space-y-2">
                                @for ($i = 0; $i < 3; $i++)
                                    <div class="flex justify-between py-2">
                                        <flux:skeleton.line class="w-16" />
                                        <flux:skeleton.line class="w-20" />
                                    </div>
                                @endfor
                            </div>
                        </flux:card>

                        {{-- Payment Methods Card Skeleton --}}
                        <flux:card>
                            <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                                <flux:skeleton class="h-5 w-44 rounded" />
                            </div>
                            <div class="p-4 space-y-2">
                                @for ($i = 0; $i < 2; $i++)
                                    <div class="flex items-center justify-between py-2 px-2 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                                        <div class="flex items-center gap-2">
                                            <flux:skeleton class="size-4 rounded" />
                                            <div class="space-y-1">
                                                <flux:skeleton.line class="w-28" />
                                                <flux:skeleton.line class="w-16" />
                                            </div>
                                        </div>
                                        <flux:skeleton class="size-6 rounded" />
                                    </div>
                                @endfor
                            </div>
                        </flux:card>

                        {{-- Action Buttons Skeleton --}}
                        <div class="space-y-2">
                            @for ($i = 0; $i < 4; $i++)
                                <flux:skeleton class="h-10 w-full rounded-lg" />
                            @endfor
                        </div>
                    </div>

                    {{-- Right Column Skeleton (2 cols wide) --}}
                    <div class="lg:col-span-2 space-y-6">
                        {{-- Recurring Payments Table Skeleton --}}
                        <flux:card>
                            <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                                <flux:skeleton class="h-5 w-40 rounded" />
                            </div>
                            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                <div class="px-4 py-3 flex items-center gap-6">
                                    <flux:skeleton.line class="w-16" />
                                    <flux:skeleton.line class="w-20" />
                                    <flux:skeleton.line class="w-28" />
                                    <flux:skeleton.line class="w-20" />
                                    <flux:skeleton.line class="w-16" />
                                    <flux:skeleton.line class="w-16" />
                                </div>
                                @for ($i = 0; $i < 3; $i++)
                                    <div class="px-4 py-3 flex items-center gap-6">
                                        <flux:skeleton.line class="w-16" />
                                        <flux:skeleton.line class="w-20" />
                                        <flux:skeleton class="h-5 w-28 rounded" />
                                        <flux:skeleton.line class="w-20" />
                                        <flux:skeleton class="h-5 w-16 rounded-full" />
                                        <flux:skeleton.line class="w-16" />
                                    </div>
                                @endfor
                            </div>
                        </flux:card>

                        {{-- Payment Plans Table Skeleton --}}
                        <flux:card>
                            <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                                <flux:skeleton class="h-5 w-36 rounded" />
                            </div>
                            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                <div class="px-4 py-3 flex items-center gap-6">
                                    <flux:skeleton.line class="w-16" />
                                    <flux:skeleton.line class="w-16" />
                                    <flux:skeleton.line class="w-16" />
                                    <flux:skeleton.line class="w-24" />
                                    <flux:skeleton.line class="w-16" />
                                </div>
                                @for ($i = 0; $i < 2; $i++)
                                    <div class="px-4 py-3 flex items-center gap-6">
                                        <flux:skeleton.line class="w-16" />
                                        <flux:skeleton.line class="w-16" />
                                        <flux:skeleton.line class="w-16" />
                                        <flux:skeleton.line class="w-24" />
                                        <flux:skeleton class="h-5 w-16 rounded-full" />
                                    </div>
                                @endfor
                            </div>
                        </flux:card>

                        {{-- Payment History Table Skeleton --}}
                        <flux:card>
                            <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                                <flux:skeleton class="h-5 w-36 rounded" />
                            </div>
                            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                <div class="px-4 py-3 flex items-center gap-6">
                                    <flux:skeleton.line class="w-20" />
                                    <flux:skeleton.line class="w-16" />
                                    <flux:skeleton.line class="w-16" />
                                    <flux:skeleton.line class="w-16" />
                                    <flux:skeleton.line class="w-24" />
                                </div>
                                @for ($i = 0; $i < 5; $i++)
                                    <div class="px-4 py-3 flex items-center gap-6">
                                        <flux:skeleton.line class="w-20" />
                                        <flux:skeleton.line class="w-16" />
                                        <flux:skeleton.line class="w-16" />
                                        <flux:skeleton class="h-5 w-16 rounded-full" />
                                        <flux:skeleton.line class="w-24" />
                                    </div>
                                @endfor
                            </div>
                        </flux:card>
                    </div>
                </div>
            </flux:skeleton.group>
        </div>
        HTML;
    }

    /**
     * Load client data from PracticeCS and related local data.
     */
    protected function loadClient(): void
    {
        $this->loading = true;

        try {
            // Get client details from PracticeCS
            $client = $this->paymentRepo->findClientByClientId($this->clientId);

            if (! $client) {
                $this->notFound = true;

                // Still load local data even if client not found in PracticeCS
                $this->loadLocalClientData();
                $this->loading = false;

                return;
            }

            $this->client = $client;

            // Get balance from PracticeCS
            $balanceData = $this->paymentRepo->getClientBalance($client['client_KEY']);
            $this->balance = $balanceData['balance'] ?? 0;

            // Get open invoices from PracticeCS
            $this->openInvoices = $this->paymentRepo->getClientOpenInvoices($client['client_KEY']);

            // Load saved payment methods
            $this->loadPaymentMethods();

            // Load payment history (from local DB, using client_id)
            $this->loadPayments();

            // Load recurring payments (from local DB, using client_id)
            $this->loadRecurringPayments();

            // Load payment plans (from local DB, using client_id)
            $this->loadPaymentPlans();

        } catch (\Exception $e) {
            Log::error('Failed to load client details', [
                'client_id' => $this->clientId,
                'error' => $e->getMessage(),
            ]);
            $this->notFound = true;
        } finally {
            $this->loading = false;
        }
    }

    /**
     * Load local data (payments, recurring, plans, customer) when client is not found in PracticeCS.
     */
    protected function loadLocalClientData(): void
    {
        // Try to find a customer record to get the client name
        $customer = Customer::where('client_id', $this->clientId)
            ->first();

        if ($customer) {
            $this->client = [
                'client_KEY' => null,
                'client_id' => $this->clientId,
                'client_name' => $customer->name ?? 'Unknown',
                'individual_first_name' => null,
                'individual_last_name' => null,
                'federal_tin' => null,
            ];
        }

        $this->loadPaymentMethods();
        $this->loadPayments();
        $this->loadRecurringPayments();
        $this->loadPaymentPlans();
    }

    /**
     * Start editing the client ID.
     */
    public function startEditingClientId(): void
    {
        $this->newClientId = $this->clientId;
        $this->editingClientId = true;
    }

    /**
     * Cancel editing the client ID.
     */
    public function cancelEditingClientId(): void
    {
        $this->editingClientId = false;
        $this->newClientId = '';
        $this->resetValidation('newClientId');
    }

    /**
     * Update the client ID across all local database tables.
     *
     * Updates the client_id in: customers, payments, recurring_payments,
     * payment_plans, and ach_entries tables within a database transaction.
     * Redirects to the new client URL after successful update.
     */
    public function updateClientId(): void
    {
        $this->validate([
            'newClientId' => ['required', 'string', 'max:50'],
        ]);

        $newId = trim($this->newClientId);

        if ($newId === $this->clientId) {
            $this->cancelEditingClientId();

            return;
        }

        // Check if the new client ID already has local records (would cause conflicts)
        $existingCustomer = Customer::where('client_id', $newId)->first();
        $currentCustomer = Customer::where('client_id', $this->clientId)->first();

        if ($existingCustomer && $currentCustomer && $existingCustomer->id !== $currentCustomer->id) {
            $this->addError('newClientId', 'Another customer already exists with this client ID.');

            return;
        }

        try {
            DB::transaction(function () use ($newId) {
                $oldId = $this->clientId;

                // Update customers table
                Customer::where('client_id', $oldId)
                    ->update(['client_id' => $newId]);

                // Update payments table
                Payment::where('client_id', $oldId)
                    ->update(['client_id' => $newId]);

                // Update recurring_payments table
                RecurringPayment::where('client_id', $oldId)
                    ->update(['client_id' => $newId]);

                // Update payment_plans table
                PaymentPlan::where('client_id', $oldId)
                    ->update(['client_id' => $newId]);

                // Update ach_entries table
                AchEntry::where('client_id', $oldId)
                    ->update(['client_id' => $newId]);

                Log::info('Client ID updated across all local tables', [
                    'old_client_id' => $oldId,
                    'new_client_id' => $newId,
                ]);
            });

            session()->flash('success', "Client ID updated from \"{$this->clientId}\" to \"{$newId}\".");

            $this->redirect(route('admin.clients.show', ['clientId' => $newId]), navigate: true);
        } catch (\Exception $e) {
            Log::error('Failed to update client ID', [
                'old_client_id' => $this->clientId,
                'new_client_id' => $newId,
                'error' => $e->getMessage(),
            ]);

            $this->addError('newClientId', 'Failed to update client ID. Please try again.');
        }
    }

    /**
     * Load saved payment methods for this client.
     */
    protected function loadPaymentMethods(): void
    {
        // Find customer by client_id (the Practice client_id stored in our Customer table)
        $customer = Customer::where('client_id', $this->clientId)->first();

        if ($customer) {
            $this->paymentMethods = $this->paymentMethodService->getPaymentMethods($customer);
        } else {
            $this->paymentMethods = collect();
        }
    }

    /**
     * Load payment history for this client.
     */
    protected function loadPayments(): void
    {
        // Payments are stored with client_id field
        $this->payments = Payment::where('client_id', $this->clientId)
            ->orderBy('created_at', 'desc')
            ->take(20)
            ->get();
    }

    /**
     * Load recurring payments for this client.
     */
    protected function loadRecurringPayments(): void
    {
        // RecurringPayments use client_id field (after our rename)
        $this->recurringPayments = RecurringPayment::where('client_id', $this->clientId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Load payment plans for this client.
     */
    protected function loadPaymentPlans(): void
    {
        // PaymentPlans use client_id field
        $this->paymentPlans = PaymentPlan::where('client_id', $this->clientId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Delete a saved payment method.
     */
    public function deletePaymentMethod(int $methodId): void
    {
        try {
            $method = CustomerPaymentMethod::find($methodId);

            if (! $method) {
                session()->flash('error', 'Payment method not found.');

                return;
            }

            $canDeleteResult = $this->paymentMethodService->canDelete($method);

            if (! $canDeleteResult['can_delete']) {
                session()->flash('error', $canDeleteResult['message']);

                return;
            }

            $this->paymentMethodService->delete($method);
            $this->loadPaymentMethods();

            session()->flash('success', 'Payment method deleted successfully.');
        } catch (\Exception $e) {
            Log::error('Failed to delete payment method', [
                'method_id' => $methodId,
                'error' => $e->getMessage(),
            ]);
            session()->flash('error', 'Failed to delete payment method.');
        }
    }

    /**
     * Get the payment method status for a recurring payment.
     *
     * Returns 'ok', 'expired', 'missing', or 'pending' (no method assigned).
     *
     * @return array{status: string, method: CustomerPaymentMethod|null}
     */
    public function getRecurringPaymentMethodStatus(RecurringPayment $recurring): array
    {
        // No payment method assigned yet (imported without method info)
        if (empty($recurring->payment_method_token)) {
            return ['status' => 'pending', 'method' => null];
        }

        // Find the matching CustomerPaymentMethod by token
        $customer = Customer::where('client_id', $this->clientId)
            ->first();

        if ($customer) {
            $method = CustomerPaymentMethod::where('customer_id', $customer->id)
                ->where('mpc_token', $recurring->payment_method_token)
                ->first();

            if ($method) {
                if ($method->isExpired()) {
                    return ['status' => 'expired', 'method' => $method];
                }

                return ['status' => 'ok', 'method' => $method];
            }
        }

        // Token exists on recurring payment but no matching saved method found
        return ['status' => 'missing', 'method' => null];
    }

    /**
     * Manually retry processing an overdue recurring payment.
     */
    public function retryRecurringPayment(int $id): void
    {
        $payment = RecurringPayment::find($id);

        if (! $payment || $payment->client_id !== $this->clientId) {
            Flux::toast('Recurring payment not found.', variant: 'danger');

            return;
        }

        if ($payment->status !== RecurringPayment::STATUS_ACTIVE) {
            Flux::toast('Only active payments can be retried.', variant: 'danger');

            return;
        }

        if (! $payment->next_payment_date || ! $payment->next_payment_date->isPast()) {
            Flux::toast('This payment is not yet due.', variant: 'danger');

            return;
        }

        $this->retrying = true;

        $previousCompletedCount = $payment->payments_completed;

        try {
            Artisan::call('payments:process-recurring', ['--id' => $id]);

            // Reload to check result
            $payment->refresh();

            if ($payment->payments_completed > $previousCompletedCount) {
                Flux::toast('Payment processed successfully.', variant: 'success');
            } else {
                $lastFailedPayment = $payment->payments()
                    ->where('status', 'failed')
                    ->latest()
                    ->first();

                $failureReason = $lastFailedPayment?->failure_reason ?? 'Unknown error';

                Flux::toast("Payment failed: {$failureReason}", variant: 'danger');
            }

            // Reload recurring payments and payment history to reflect changes
            $this->loadRecurringPayments();
            $this->loadPayments();
        } catch (\Exception $e) {
            Log::error('Manual retry of recurring payment failed from client details', [
                'recurring_payment_id' => $id,
                'client_id' => $this->clientId,
                'error' => $e->getMessage(),
            ]);

            Flux::toast('An error occurred while processing the payment.', variant: 'danger');
        } finally {
            $this->retrying = false;
        }
    }

    /**
     * Open the assign payment method modal for a recurring payment.
     *
     * Formats the available payment methods into a plain array for the Alpine $wire
     * reactive getter pattern (bypasses Flux modal wire:ignore.self morph issue).
     *
     * @param  int  $recurringPaymentId  The recurring payment to assign a method to
     */
    public function openAssignMethodModal(int $recurringPaymentId): void
    {
        $recurring = RecurringPayment::find($recurringPaymentId);

        if (! $recurring || $recurring->client_id !== $this->clientId) {
            Flux::toast('Recurring payment not found.', variant: 'danger');

            return;
        }

        if (! empty($recurring->payment_method_token)) {
            Flux::toast('This recurring payment already has a payment method assigned.', variant: 'warning');

            return;
        }

        $this->assigningRecurringPaymentId = $recurringPaymentId;
        $this->assignModalDetails = $this->formatAssignModalDetails($recurring);

        $this->modal('assign-payment-method')->show();
    }

    /**
     * Format the assign modal details for Alpine consumption.
     *
     * @return array{recurring_id: int, recurring_amount: string, recurring_frequency: string, methods: array}
     */
    protected function formatAssignModalDetails(RecurringPayment $recurring): array
    {
        // Re-query payment methods from the database to ensure we have fresh
        // Eloquent models with accessors/methods (Livewire's dehydration/rehydration
        // of Collection properties can strip model functionality).
        $customer = Customer::where('client_id', $this->clientId)->first();
        $freshMethods = $customer
            ? CustomerPaymentMethod::where('customer_id', $customer->id)->get()
            : collect();

        $methods = [];

        foreach ($freshMethods as $method) {
            // Skip expired cards
            if ($method->isExpired()) {
                continue;
            }

            $methods[] = [
                'id' => $method->id,
                'type' => $method->type,
                'display_name' => $method->display_name,
                'is_default' => $method->is_default,
                'last_four' => $method->last_four,
                'brand' => $method->brand,
                'bank_name' => $method->bank_name,
                'exp_display' => $method->expiration_display,
                'is_expiring_soon' => $method->isExpiringSoon(),
            ];
        }

        return [
            'recurring_id' => $recurring->id,
            'recurring_amount' => number_format($recurring->amount, 2),
            'recurring_frequency' => $recurring->frequency_label,
            'recurring_description' => $recurring->description ?? '',
            'methods' => $methods,
        ];
    }

    /**
     * Assign a saved payment method to the recurring payment.
     *
     * Updates the recurring payment with the chosen method's token, type, and last four.
     * Also links the customer_id if not already set, and activates pending payments.
     *
     * @param  int  $methodId  The CustomerPaymentMethod ID to assign
     */
    public function assignPaymentMethod(int $methodId): void
    {
        $recurring = RecurringPayment::find($this->assigningRecurringPaymentId);

        if (! $recurring || $recurring->client_id !== $this->clientId) {
            Flux::toast('Recurring payment not found.', variant: 'danger');
            $this->closeAssignModal();

            return;
        }

        $method = CustomerPaymentMethod::find($methodId);

        if (! $method) {
            Flux::toast('Payment method not found.', variant: 'danger');

            return;
        }

        // Verify the method belongs to a customer for this client
        $customer = Customer::where('client_id', $this->clientId)->first();

        if (! $customer || $method->customer_id !== $customer->id) {
            Flux::toast('Payment method does not belong to this client.', variant: 'danger');

            return;
        }

        if ($method->isExpired()) {
            Flux::toast('Cannot assign an expired payment method.', variant: 'danger');

            return;
        }

        try {
            // Update the recurring payment with the chosen method
            $recurring->payment_method_token = $method->mpc_token;
            $recurring->payment_method_type = $method->type;
            $recurring->payment_method_last_four = $method->last_four;

            // Link customer_id if not already set
            if (! $recurring->customer_id) {
                $recurring->customer_id = $customer->id;
            }

            // Activate if currently pending
            if ($recurring->status === RecurringPayment::STATUS_PENDING) {
                $recurring->status = RecurringPayment::STATUS_ACTIVE;
            }

            $recurring->save();

            $this->closeAssignModal();
            $this->loadRecurringPayments();
            $this->loadPaymentMethods();

            Flux::toast('Payment method assigned successfully.', variant: 'success');
        } catch (\Exception $e) {
            Log::error('Failed to assign payment method to recurring payment', [
                'recurring_payment_id' => $recurring->id,
                'method_id' => $methodId,
                'client_id' => $this->clientId,
                'error' => $e->getMessage(),
            ]);

            Flux::toast('Failed to assign payment method.', variant: 'danger');
        }
    }

    /**
     * Close the assign payment method modal and reset state.
     */
    public function closeAssignModal(): void
    {
        $this->assigningRecurringPaymentId = null;
        $this->assignModalDetails = [];
        $this->modal('assign-payment-method')->close();
    }

    /**
     * Open the send payment request modal.
     *
     * Loads all email addresses from PracticeCS for the client and resets the form.
     */
    public function openPaymentRequestModal(): void
    {
        if (! $this->client) {
            Flux::toast('Client data not available.', variant: 'danger');

            return;
        }

        // Load all email addresses from PracticeCS via Eloquent
        $this->clientEmails = [];
        if (! empty($this->client['client_KEY'])) {
            $client = \App\Models\Client::find($this->client['client_KEY']);
            if ($client && $client->contact) {
                $this->clientEmails = $client->contact->emails()
                    ->pluck('email')
                    ->map(fn (string $email) => strtolower(trim($email)))
                    ->unique()
                    ->values()
                    ->toArray();
            }
        }

        // Pre-select the first email if available
        $this->paymentRequestEmail = $this->clientEmails[0] ?? '';

        $this->paymentRequestAmount = 0;
        $this->paymentRequestMessage = '';
        $this->paymentRequestInvoices = [];

        $this->resetValidation();
        $this->modal('send-payment-request')->show();
    }

    /**
     * Recalculate payment request amount when invoice selection changes.
     *
     * Called by Livewire when the checkbox group bound to paymentRequestInvoices
     * adds or removes values. The array holds ledger_entry_KEY strings.
     */
    public function updatedPaymentRequestInvoices(): void
    {
        $total = 0;
        foreach ($this->openInvoices as $inv) {
            if (in_array((string) $inv['ledger_entry_KEY'], $this->paymentRequestInvoices, true)) {
                $total += $inv['open_amount'];
            }
        }
        $this->paymentRequestAmount = round($total, 2);
    }

    /**
     * Send the payment request email to the client.
     */
    public function sendPaymentRequest(): void
    {
        $this->validate([
            'paymentRequestEmail' => ['required', 'email'],
            'paymentRequestAmount' => ['required', 'numeric', 'min:0.01'],
            'paymentRequestMessage' => ['nullable', 'string', 'max:1000'],
        ], [
            'paymentRequestEmail.required' => 'Email address is required.',
            'paymentRequestEmail.email' => 'Please enter a valid email address.',
            'paymentRequestAmount.required' => 'Amount is required.',
            'paymentRequestAmount.min' => 'Amount must be at least $0.01.',
        ]);

        try {
            // Build invoice data for storage
            $invoiceData = [];
            foreach ($this->openInvoices as $inv) {
                if (in_array((string) $inv['ledger_entry_KEY'], $this->paymentRequestInvoices, true)) {
                    $invoiceData[] = [
                        'invoice_number' => $inv['invoice_number'],
                        'description' => $inv['description'] ?? '',
                        'open_amount' => $inv['open_amount'],
                        'ledger_entry_KEY' => $inv['ledger_entry_KEY'] ?? null,
                        'client_KEY' => $inv['client_KEY'] ?? $this->client['client_KEY'],
                    ];
                }
            }

            // Create the payment request
            $paymentRequest = PaymentRequest::create([
                'client_key' => $this->client['client_KEY'] ?? null,
                'client_id' => $this->client['client_id'],
                'client_name' => $this->client['client_name'] ?? $this->client['description'] ?? 'Unknown',
                'email' => $this->paymentRequestEmail,
                'amount' => $this->paymentRequestAmount,
                'invoices' => ! empty($invoiceData) ? $invoiceData : null,
                'message' => ! empty($this->paymentRequestMessage) ? $this->paymentRequestMessage : null,
                'sent_by' => auth()->id(),
            ]);

            // Send the email
            Mail::to($this->paymentRequestEmail)
                ->send(new PaymentRequestMail($paymentRequest));

            // Log the activity
            AdminActivity::log(
                action: AdminActivity::ACTION_SENT,
                model: $paymentRequest,
                description: "Sent payment request for \${$this->paymentRequestAmount} to {$this->paymentRequestEmail}",
            );

            $this->modal('send-payment-request')->close();

            Flux::toast(
                "Payment request sent to {$this->paymentRequestEmail}",
                variant: 'success',
            );

        } catch (\Exception $e) {
            Log::error('Failed to send payment request', [
                'client_id' => $this->clientId,
                'email' => $this->paymentRequestEmail,
                'error' => $e->getMessage(),
            ]);

            $this->addError('paymentRequestEmail', 'Failed to send payment request: '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.admin.clients.client-details');
    }
}
