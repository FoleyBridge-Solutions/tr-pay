<?php

namespace App\Livewire\Admin\RecurringPayments;

use App\Models\AdminActivity;
use App\Models\Customer;
use App\Models\CustomerPaymentMethod;
use App\Models\RecurringPayment;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Create Recurring Payment Component
 *
 * Manual entry form for creating recurring payments.
 */
#[Layout('layouts.admin')]
class Create extends Component
{
    // Client selection
    public string $searchType = 'name'; // 'name', 'client_id', or 'tax_id'

    public string $searchQuery = '';

    public array $searchResults = [];

    public ?array $selectedClient = null;

    // Payment details
    #[Validate('required|numeric|min:0.01')]
    public string $amount = '';

    #[Validate('required|in:weekly,biweekly,monthly,quarterly,yearly')]
    public string $frequency = 'monthly';

    #[Validate('required|date')]
    public string $startDate = '';

    #[Validate('nullable|date|after:startDate')]
    public string $endDate = '';

    #[Validate('nullable|integer|min:1')]
    public ?int $maxOccurrences = null;

    #[Validate('nullable|string|max:255')]
    public string $description = '';

    // Payment method
    #[Validate('required|in:card,ach,saved,none')]
    public string $paymentMethodType = 'card';

    // Saved payment methods
    public ?int $savedPaymentMethodId = null;

    public Collection $savedPaymentMethods;

    // Card fields
    #[Validate('required_if:paymentMethodType,card')]
    public string $cardNumber = '';

    #[Validate('required_if:paymentMethodType,card')]
    public string $cardExpiry = '';

    public string $cardCvv = '';

    public string $cardName = '';

    // ACH fields
    #[Validate('required_if:paymentMethodType,ach')]
    public string $routingNumber = '';

    #[Validate('required_if:paymentMethodType,ach')]
    public string $accountNumber = '';

    public string $accountType = 'checking';

    public string $accountName = '';

    // State
    public bool $processing = false;

    public ?string $errorMessage = null;

    public ?RecurringPayment $createdPayment = null;

    public function mount(): void
    {
        $this->startDate = now()->format('Y-m-d');
        $this->savedPaymentMethods = collect();
    }

    /**
     * Search for clients.
     */
    public function searchClients(): void
    {
        $this->searchResults = [];
        $this->errorMessage = null;

        if (strlen($this->searchQuery) < 2) {
            return;
        }

        try {
            if ($this->searchType === 'client_id') {
                $result = DB::connection('sqlsrv')->select('
                    SELECT TOP 20
                        client_KEY,
                        client_id,
                        description AS client_name,
                        individual_first_name,
                        individual_last_name,
                        federal_tin
                    FROM Client
                    WHERE client_id LIKE ?
                    ORDER BY description
                ', ["%{$this->searchQuery}%"]);
            } elseif ($this->searchType === 'tax_id') {
                // Search by last 4 digits of SSN/EIN (federal_tin)
                $last4 = preg_replace('/\D/', '', $this->searchQuery);
                if (strlen($last4) !== 4) {
                    $this->errorMessage = 'Please enter exactly 4 digits for Tax ID search.';

                    return;
                }
                $result = DB::connection('sqlsrv')->select('
                    SELECT TOP 20
                        client_KEY,
                        client_id,
                        description AS client_name,
                        individual_first_name,
                        individual_last_name,
                        federal_tin
                    FROM Client
                    WHERE RIGHT(REPLACE(REPLACE(federal_tin, \'-\', \'\'), \' \', \'\'), 4) = ?
                    ORDER BY description
                ', [$last4]);
            } else {
                $result = DB::connection('sqlsrv')->select('
                    SELECT TOP 20
                        client_KEY,
                        client_id,
                        description AS client_name,
                        individual_first_name,
                        individual_last_name,
                        federal_tin
                    FROM Client
                    WHERE description LIKE ?
                       OR individual_last_name LIKE ?
                       OR individual_first_name LIKE ?
                    ORDER BY description
                ', ["%{$this->searchQuery}%", "%{$this->searchQuery}%", "%{$this->searchQuery}%"]);
            }

            $this->searchResults = array_map(fn ($r) => (array) $r, $result);
        } catch (\Exception $e) {
            Log::error('Client search failed', ['error' => $e->getMessage()]);
            $this->errorMessage = 'Failed to search clients. Please try again.';
        }
    }

    /**
     * Select a client.
     */
    public function selectClient(int $clientKey): void
    {
        foreach ($this->searchResults as $client) {
            if ($client['client_KEY'] == $clientKey) {
                $this->selectedClient = $client;
                $this->searchResults = [];
                $this->searchQuery = '';

                // Load saved payment methods for this client
                $this->loadSavedPaymentMethods();

                return;
            }
        }
    }

    /**
     * Load saved payment methods for the selected client.
     */
    protected function loadSavedPaymentMethods(): void
    {
        if (! $this->selectedClient) {
            $this->savedPaymentMethods = collect();

            return;
        }

        $customer = Customer::where('client_key', $this->selectedClient['client_KEY'])->first();

        if ($customer) {
            $this->savedPaymentMethods = $customer->customerPaymentMethods()
                ->orderByDesc('is_default')
                ->orderByDesc('created_at')
                ->get();
        } else {
            $this->savedPaymentMethods = collect();
        }
    }

    /**
     * Get the selected saved payment method.
     */
    public function getSelectedSavedMethod(): ?CustomerPaymentMethod
    {
        if (! $this->savedPaymentMethodId) {
            return null;
        }

        return $this->savedPaymentMethods->firstWhere('id', $this->savedPaymentMethodId);
    }

    /**
     * Clear selected client.
     */
    public function clearClient(): void
    {
        $this->selectedClient = null;
        $this->savedPaymentMethods = collect();
        $this->savedPaymentMethodId = null;

        // Reset to card if was on saved
        if ($this->paymentMethodType === 'saved') {
            $this->paymentMethodType = 'card';
        }
    }

    /**
     * Get available frequencies.
     */
    #[Computed]
    public function frequencies(): array
    {
        return RecurringPayment::getFrequencies();
    }

    /**
     * Validate payment method fields.
     */
    protected function validatePaymentMethod(): bool
    {
        // No payment method selected - this is valid for pending recurring payments
        if ($this->paymentMethodType === 'none') {
            return true;
        }

        if ($this->paymentMethodType === 'saved') {
            if (! $this->savedPaymentMethodId) {
                $this->errorMessage = 'Please select a saved payment method.';

                return false;
            }

            $method = $this->savedPaymentMethods->firstWhere('id', $this->savedPaymentMethodId);
            if (! $method) {
                $this->errorMessage = 'Selected payment method not found.';

                return false;
            }

            // Warn about expired cards but allow selection
            if ($method->isExpired()) {
                $this->errorMessage = 'The selected card has expired. Please choose a different payment method.';

                return false;
            }

            return true;
        }

        if ($this->paymentMethodType === 'card') {
            $cardNumber = preg_replace('/\D/', '', $this->cardNumber);
            if (strlen($cardNumber) < 13 || strlen($cardNumber) > 19) {
                $this->errorMessage = 'Please enter a valid card number.';

                return false;
            }
            if (empty($this->cardExpiry) || ! preg_match('/^\d{2}\/\d{2}$/', $this->cardExpiry)) {
                $this->errorMessage = 'Please enter a valid expiry date (MM/YY).';

                return false;
            }
        } else {
            $routing = preg_replace('/\D/', '', $this->routingNumber);
            if (strlen($routing) !== 9) {
                $this->errorMessage = 'Please enter a valid 9-digit routing number.';

                return false;
            }
            if (empty($this->accountNumber) || strlen($this->accountNumber) < 4) {
                $this->errorMessage = 'Please enter a valid account number.';

                return false;
            }
        }

        return true;
    }

    /**
     * Create the recurring payment.
     */
    public function create(): void
    {
        $this->errorMessage = null;

        // Validate client is selected
        if (! $this->selectedClient) {
            $this->errorMessage = 'Please select a client.';

            return;
        }

        // Validate form
        $this->validate();

        // Validate payment method
        if (! $this->validatePaymentMethod()) {
            return;
        }

        $this->processing = true;

        try {
            // Tokenize payment method (encrypt for storage)
            $token = $this->tokenizePaymentMethod();
            $lastFour = $this->getLastFour();

            // Parse dates
            $startDate = Carbon::parse($this->startDate);
            $endDate = $this->endDate ? Carbon::parse($this->endDate) : null;

            // Calculate next payment date
            $nextPaymentDate = $startDate->copy();
            if ($nextPaymentDate->lt(now())) {
                $nextPaymentDate = $this->calculateNextOccurrence($startDate);
            }

            // Get or create customer
            $customer = Customer::where('client_key', $this->selectedClient['client_KEY'])->first();
            if (! $customer) {
                $customer = Customer::create([
                    'name' => $this->selectedClient['client_name'],
                    'client_key' => $this->selectedClient['client_KEY'],
                ]);
            }

            // Get the actual payment method type (resolve 'saved' to underlying type)
            $actualPaymentMethodType = $this->getActualPaymentMethodType();

            // Determine status based on payment method availability
            $hasPaymentMethod = $this->paymentMethodType !== 'none';
            $status = $hasPaymentMethod ? RecurringPayment::STATUS_ACTIVE : RecurringPayment::STATUS_PENDING;

            // Create the recurring payment
            $this->createdPayment = RecurringPayment::create([
                'customer_id' => $customer->id,
                'client_id' => $this->selectedClient['client_id'],
                'client_name' => $this->selectedClient['client_name'],
                'frequency' => $this->frequency,
                'amount' => (float) $this->amount,
                'description' => $this->description ?: null,
                'payment_method_type' => $actualPaymentMethodType,
                'payment_method_token' => $token,
                'payment_method_last_four' => $lastFour,
                'status' => $status,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'max_occurrences' => $this->maxOccurrences,
                'next_payment_date' => $hasPaymentMethod ? $nextPaymentDate : null,
                'metadata' => [
                    'created_manually' => true,
                    'created_at' => now()->toIso8601String(),
                    'used_saved_method' => $this->paymentMethodType === 'saved',
                    'saved_method_id' => $this->paymentMethodType === 'saved' ? $this->savedPaymentMethodId : null,
                    'awaiting_payment_method' => ! $hasPaymentMethod,
                ],
            ]);

            Log::info('Recurring payment created manually', [
                'id' => $this->createdPayment->id,
                'client_name' => $this->selectedClient['client_name'],
                'amount' => $this->amount,
                'frequency' => $this->frequency,
                'status' => $status,
                'has_payment_method' => $hasPaymentMethod,
            ]);

            // Log the activity
            AdminActivity::log(
                AdminActivity::ACTION_CREATED,
                $this->createdPayment,
                description: "Created recurring payment for {$this->selectedClient['client_name']} - \$".number_format((float) $this->amount, 2)." {$this->frequency}".($hasPaymentMethod ? '' : ' (pending payment method)'),
                newValues: [
                    'id' => $this->createdPayment->id,
                    'client_id' => $this->selectedClient['client_id'],
                    'client_name' => $this->selectedClient['client_name'],
                    'amount' => (float) $this->amount,
                    'frequency' => $this->frequency,
                    'status' => $status,
                    'start_date' => $this->startDate,
                    'end_date' => $this->endDate ?: null,
                    'max_occurrences' => $this->maxOccurrences,
                    'next_payment_date' => $hasPaymentMethod ? $nextPaymentDate->format('Y-m-d') : null,
                    'payment_method' => $actualPaymentMethodType,
                    'payment_method_last_four' => $lastFour,
                    'used_saved_method' => $this->paymentMethodType === 'saved',
                    'saved_method_id' => $this->paymentMethodType === 'saved' ? $this->savedPaymentMethodId : null,
                    'description' => $this->description ?: null,
                ]
            );

            Flux::toast($hasPaymentMethod
                ? 'Recurring payment created successfully.'
                : 'Recurring payment created. Payment method must be added before payments can be processed.');
        } catch (\Exception $e) {
            Log::error('Failed to create recurring payment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->errorMessage = 'Failed to create recurring payment. Please try again.';
        } finally {
            $this->processing = false;
        }
    }

    /**
     * Tokenize (encrypt) the payment method.
     */
    protected function tokenizePaymentMethod(): ?string
    {
        if ($this->paymentMethodType === 'none') {
            return null;
        }

        if ($this->paymentMethodType === 'saved') {
            $method = $this->getSelectedSavedMethod();
            if ($method) {
                // Return the MPC token directly for saved methods
                return $method->mpc_token;
            }
            throw new \Exception('Saved payment method not found.');
        }

        if ($this->paymentMethodType === 'card') {
            $data = [
                'type' => 'card',
                'number' => preg_replace('/\D/', '', $this->cardNumber),
                'expiry' => $this->cardExpiry,
                'cvv' => $this->cardCvv,
                'name' => $this->cardName,
            ];
        } else {
            $data = [
                'type' => 'ach',
                'routing' => preg_replace('/\D/', '', $this->routingNumber),
                'account' => preg_replace('/\D/', '', $this->accountNumber),
                'account_type' => $this->accountType,
                'name' => $this->accountName,
            ];
        }

        return encrypt(json_encode($data));
    }

    /**
     * Get last 4 digits of payment method.
     */
    protected function getLastFour(): ?string
    {
        if ($this->paymentMethodType === 'none') {
            return null;
        }

        if ($this->paymentMethodType === 'saved') {
            $method = $this->getSelectedSavedMethod();

            return $method?->last_four ?? '****';
        }

        if ($this->paymentMethodType === 'card') {
            $number = preg_replace('/\D/', '', $this->cardNumber);

            return substr($number, -4);
        } else {
            return substr($this->accountNumber, -4);
        }
    }

    /**
     * Get the actual payment method type (card or ach) for storage.
     * Resolves 'saved' to the underlying type.
     */
    protected function getActualPaymentMethodType(): ?string
    {
        if ($this->paymentMethodType === 'none') {
            return null;
        }

        if ($this->paymentMethodType === 'saved') {
            $method = $this->getSelectedSavedMethod();

            return $method?->type ?? 'card';
        }

        return $this->paymentMethodType;
    }

    /**
     * Calculate the next occurrence from a past start date.
     */
    protected function calculateNextOccurrence(Carbon $startDate): Carbon
    {
        $now = now()->startOfDay();
        $nextDate = $startDate->copy();

        while ($nextDate->lt($now)) {
            $nextDate = match ($this->frequency) {
                'weekly' => $nextDate->addWeek(),
                'biweekly' => $nextDate->addWeeks(2),
                'monthly' => $nextDate->addMonth(),
                'quarterly' => $nextDate->addMonths(3),
                'yearly' => $nextDate->addYear(),
                default => $nextDate->addMonth(),
            };
        }

        return $nextDate;
    }

    /**
     * Reset form and create another.
     */
    public function createAnother(): void
    {
        $this->reset([
            'selectedClient',
            'amount',
            'frequency',
            'startDate',
            'endDate',
            'maxOccurrences',
            'description',
            'paymentMethodType',
            'cardNumber',
            'cardExpiry',
            'cardCvv',
            'cardName',
            'routingNumber',
            'accountNumber',
            'accountType',
            'accountName',
            'createdPayment',
            'errorMessage',
            'savedPaymentMethodId',
        ]);
        $this->frequency = 'monthly';
        $this->paymentMethodType = 'card';
        $this->accountType = 'checking';
        $this->startDate = now()->format('Y-m-d');
        $this->savedPaymentMethods = collect();
    }

    public function render()
    {
        return view('livewire.admin.recurring-payments.create');
    }
}
