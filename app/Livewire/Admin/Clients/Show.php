<?php

namespace App\Livewire\Admin\Clients;

use App\Models\Customer;
use App\Models\CustomerPaymentMethod;
use App\Models\Payment;
use App\Models\PaymentPlan;
use App\Models\RecurringPayment;
use App\Repositories\PaymentRepository;
use App\Services\CustomerPaymentMethodService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Client Show Component
 *
 * Displays detailed information for a single client from PracticeCS.
 */
#[Layout('layouts.admin')]
class Show extends Component
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

    protected PaymentRepository $paymentRepo;

    protected CustomerPaymentMethodService $paymentMethodService;

    public function boot(PaymentRepository $paymentRepo, CustomerPaymentMethodService $paymentMethodService): void
    {
        $this->paymentRepo = $paymentRepo;
        $this->paymentMethodService = $paymentMethodService;
        $this->paymentMethods = collect();
        $this->payments = collect();
        $this->recurringPayments = collect();
        $this->paymentPlans = collect();
    }

    /**
     * Mount the component with the client ID from the route.
     */
    public function mount(string $clientId): void
    {
        $this->clientId = $clientId;
        $this->loadClient();
    }

    /**
     * Load client data from PracticeCS and related local data.
     */
    protected function loadClient(): void
    {
        $this->loading = true;

        try {
            // Get client details from PracticeCS
            $client = DB::connection('sqlsrv')->selectOne('
                SELECT
                    client_KEY,
                    client_id,
                    description AS client_name,
                    individual_first_name,
                    individual_last_name,
                    federal_tin
                FROM Client
                WHERE client_id = ?
            ', [$this->clientId]);

            if (! $client) {
                $this->notFound = true;

                // Still load local data even if client not found in PracticeCS
                $this->loadLocalClientData();
                $this->loading = false;

                return;
            }

            $this->client = (array) $client;

            // Get balance from PracticeCS
            $balanceData = $this->paymentRepo->getClientBalance($client->client_KEY);
            $this->balance = $balanceData['balance'] ?? 0;

            // Get open invoices from PracticeCS
            $this->openInvoices = $this->paymentRepo->getClientOpenInvoices($client->client_KEY);

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
            ->orWhere('client_key', $this->clientId)
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
     * Load saved payment methods for this client.
     */
    protected function loadPaymentMethods(): void
    {
        // Find customer by client_id (the Practice client_id stored in our Customer table)
        $customer = Customer::where('client_id', $this->clientId)->first();

        // Also try client_key field which may store the client_id in some records
        if (! $customer) {
            $customer = Customer::where('client_key', $this->clientId)->first();
        }

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
        // Payments are stored with client_key field containing the client_id value
        $this->payments = Payment::where('client_key', $this->clientId)
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
        // PaymentPlans use client_key field which may contain client_id
        $this->paymentPlans = PaymentPlan::where('client_key', $this->clientId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Delete a saved payment method.
     */
    public function deletePaymentMethod(int $methodId): void
    {
        try {
            $method = \App\Models\CustomerPaymentMethod::find($methodId);

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
            ->orWhere('client_key', $this->clientId)
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

    public function render()
    {
        return view('livewire.admin.clients.show');
    }
}
