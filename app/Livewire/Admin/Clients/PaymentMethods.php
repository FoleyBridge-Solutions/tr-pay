<?php

namespace App\Livewire\Admin\Clients;

use App\Livewire\Admin\Concerns\ValidatesPaymentMethod;
use App\Models\AdminActivity;
use App\Models\Customer;
use App\Models\CustomerPaymentMethod;
use App\Services\CustomerPaymentMethodService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Client Payment Methods Management
 *
 * Admin page for viewing and managing saved payment methods for a client.
 * Supports creating new payment methods, setting defaults, and deletion.
 */
#[Layout('layouts::admin')]
class PaymentMethods extends Component
{
    use ValidatesPaymentMethod;

    // Client data
    #[Url(as: 'client')]
    public ?string $clientId = null;

    public ?array $clientInfo = null;

    public ?Customer $customer = null;

    public Collection $paymentMethods;

    // Add Payment Method Form
    public bool $showAddForm = false;

    public string $paymentType = 'card'; // 'card' or 'ach'

    public string $cardNumber = '';

    public string $cardExpiry = '';

    public string $cardCvv = '';

    public string $cardName = '';

    public string $routingNumber = '';

    public string $accountNumber = '';

    public string $accountName = '';

    public string $accountType = 'checking';

    public string $bankName = '';

    public bool $isBusiness = false;

    public string $nickname = '';

    public bool $setAsDefault = false;

    // Delete confirmation
    public ?int $deleteMethodId = null;

    public ?CustomerPaymentMethod $methodToDelete = null;

    /**
     * Pre-formatted delete method details for Alpine display (bypasses modal morph issues).
     *
     * @var array<string, mixed>
     */
    public array $deleteMethodDetails = [];

    // Processing state
    public bool $processing = false;

    public ?string $errorMessage = null;

    public ?string $successMessage = null;

    protected CustomerPaymentMethodService $paymentMethodService;

    public function boot(CustomerPaymentMethodService $paymentMethodService): void
    {
        $this->paymentMethodService = $paymentMethodService;
        $this->paymentMethods = collect();
    }

    public function mount(): void
    {
        if ($this->clientId) {
            $this->loadClient();
        }
    }

    /**
     * Load client data from PracticeCS and local Customer model.
     */
    protected function loadClient(): void
    {
        if (! $this->clientId) {
            return;
        }

        try {
            // Get client info from PracticeCS
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
                $this->errorMessage = 'Client not found.';

                return;
            }

            $this->clientInfo = (array) $client;

            // Find or create local Customer record
            $this->customer = Customer::firstOrCreate(
                ['client_id' => $this->clientId],
                [
                    'name' => $client->client_name,
                ]
            );

            // Load payment methods
            $this->loadPaymentMethods();
        } catch (\Exception $e) {
            Log::error('Failed to load client', ['error' => $e->getMessage()]);
            $this->errorMessage = 'Failed to load client data.';
        }
    }

    /**
     * Load saved payment methods for the customer.
     */
    protected function loadPaymentMethods(): void
    {
        if (! $this->customer) {
            $this->paymentMethods = collect();

            return;
        }

        $this->paymentMethods = $this->paymentMethodService->getPaymentMethods($this->customer);
    }

    /**
     * Toggle the add payment method form.
     */
    public function toggleAddForm(): void
    {
        $this->showAddForm = ! $this->showAddForm;
        $this->resetForm();
    }

    /**
     * Reset the form fields.
     */
    protected function resetForm(): void
    {
        $this->paymentType = 'card';
        $this->cardNumber = '';
        $this->cardExpiry = '';
        $this->cardCvv = '';
        $this->cardName = '';
        $this->routingNumber = '';
        $this->accountNumber = '';
        $this->accountName = '';
        $this->accountType = 'checking';
        $this->bankName = '';
        $this->isBusiness = false;
        $this->nickname = '';
        $this->setAsDefault = false;
        $this->errorMessage = null;
    }

    /**
     * Create a new payment method.
     */
    public function createPaymentMethod(): void
    {
        $this->errorMessage = null;
        $this->successMessage = null;

        if (! $this->customer) {
            $this->errorMessage = 'No customer loaded.';

            return;
        }

        // Validate based on type
        if (! $this->validatePaymentMethod()) {
            return;
        }

        $this->processing = true;

        try {
            if ($this->paymentType === 'card') {
                // Parse expiry
                $expiryParts = explode('/', $this->cardExpiry);
                $expMonth = (int) ($expiryParts[0] ?? 0);
                $expYear = (int) ($expiryParts[1] ?? 0);
                if ($expYear < 100) {
                    $expYear += 2000;
                }

                $paymentMethod = $this->paymentMethodService->createFromCardDetails(
                    $this->customer,
                    [
                        'number' => preg_replace('/\D/', '', $this->cardNumber),
                        'exp_month' => $expMonth,
                        'exp_year' => $expYear,
                        'cvc' => $this->cardCvv,
                        'name' => $this->cardName,
                    ],
                    $this->nickname ?: null,
                    $this->setAsDefault
                );
            } else {
                $paymentMethod = $this->paymentMethodService->createFromCheckDetails(
                    $this->customer,
                    [
                        'routing_number' => preg_replace('/\D/', '', $this->routingNumber),
                        'account_number' => preg_replace('/\D/', '', $this->accountNumber),
                        'account_type' => $this->accountType,
                        'name' => $this->accountName,
                        'is_business' => $this->isBusiness,
                    ],
                    $this->bankName ?: null,
                    $this->nickname ?: null,
                    $this->setAsDefault
                );
            }

            // Log the activity
            AdminActivity::log(
                AdminActivity::ACTION_CREATED,
                $paymentMethod,
                description: "Added payment method {$paymentMethod->display_name} for {$this->clientInfo['client_name']}",
                newValues: [
                    'id' => $paymentMethod->id,
                    'client_id' => $this->clientInfo['client_id'] ?? null,
                    'client_name' => $this->clientInfo['client_name'],
                    'type' => $paymentMethod->type,
                    'last_four' => $paymentMethod->last_four,
                    'brand' => $paymentMethod->brand,
                    'is_default' => $paymentMethod->is_default,
                    'nickname' => $paymentMethod->nickname,
                ]
            );

            $this->successMessage = 'Payment method added successfully.';
            $this->showAddForm = false;
            $this->resetForm();
            $this->loadPaymentMethods();
        } catch (\Exception $e) {
            Log::error('Failed to create payment method', [
                'customer_id' => $this->customer->id,
                'error' => $e->getMessage(),
            ]);
            $this->errorMessage = 'Failed to add payment method: '.$e->getMessage();
        } finally {
            $this->processing = false;
        }
    }

    /**
     * Get the property name that holds the payment method type.
     *
     * This component uses $paymentType instead of $paymentMethodType.
     */
    protected function paymentTypeProperty(): string
    {
        return 'paymentType';
    }

    /**
     * Get the supported payment method types.
     *
     * Payment method management only supports card and ACH (no saved).
     */
    protected function supportedPaymentTypes(): array
    {
        return ['card', 'ach'];
    }

    /**
     * Set a payment method as default.
     */
    public function setDefault(int $methodId): void
    {
        $this->errorMessage = null;
        $this->successMessage = null;

        try {
            $method = CustomerPaymentMethod::find($methodId);

            if (! $method || $method->customer_id !== $this->customer?->id) {
                $this->errorMessage = 'Payment method not found.';

                return;
            }

            $this->paymentMethodService->setAsDefault($method);

            AdminActivity::log(
                AdminActivity::ACTION_UPDATED,
                $method,
                description: "Set {$method->display_name} as default for {$this->clientInfo['client_name']}",
                newValues: [
                    'id' => $method->id,
                    'client_id' => $this->clientInfo['client_id'] ?? null,
                    'client_name' => $this->clientInfo['client_name'],
                    'type' => $method->type,
                    'last_four' => $method->last_four,
                    'is_default' => true,
                ]
            );

            $this->successMessage = 'Default payment method updated.';
            $this->loadPaymentMethods();
        } catch (\Exception $e) {
            Log::error('Failed to set default payment method', ['error' => $e->getMessage()]);
            $this->errorMessage = 'Failed to update default payment method.';
        }
    }

    /**
     * Open delete confirmation modal.
     */
    public function confirmDelete(int $methodId): void
    {
        $this->deleteMethodId = $methodId;
        $this->methodToDelete = CustomerPaymentMethod::find($methodId);
        $this->deleteMethodDetails = $this->formatDeleteMethodDetails($this->methodToDelete);
        $this->errorMessage = null;
        $this->modal('delete-payment-method')->show();
    }

    /**
     * Close delete confirmation modal.
     */
    public function cancelDelete(): void
    {
        $this->modal('delete-payment-method')->close();
        $this->deleteMethodId = null;
        $this->methodToDelete = null;
        $this->deleteMethodDetails = [];
    }

    /**
     * Format payment method data for Alpine display in the delete confirmation modal.
     *
     * @return array<string, mixed>
     */
    protected function formatDeleteMethodDetails(?CustomerPaymentMethod $method): array
    {
        if (! $method) {
            return [];
        }

        return [
            'display_name' => $method->display_name ?? 'this payment method',
            'is_linked_to_active_plans' => $method->isLinkedToActivePlans(),
        ];
    }

    /**
     * Delete a payment method.
     */
    public function deletePaymentMethod(): void
    {
        $this->errorMessage = null;
        $this->successMessage = null;

        if (! $this->deleteMethodId) {
            return;
        }

        try {
            $method = CustomerPaymentMethod::find($this->deleteMethodId);

            if (! $method || $method->customer_id !== $this->customer?->id) {
                $this->errorMessage = 'Payment method not found.';
                $this->cancelDelete();

                return;
            }

            // Check if can delete
            $canDeleteResult = $this->paymentMethodService->canDelete($method);

            if (! $canDeleteResult['can_delete']) {
                $this->errorMessage = $canDeleteResult['message'];
                $this->cancelDelete();

                return;
            }

            $displayName = $method->display_name;
            $deletedMethodData = [
                'id' => $method->id,
                'client_id' => $this->clientInfo['client_id'] ?? null,
                'client_name' => $this->clientInfo['client_name'],
                'type' => $method->type,
                'last_four' => $method->last_four,
                'brand' => $method->brand,
                'was_default' => $method->is_default,
            ];

            // Delete the method
            $this->paymentMethodService->delete($method);

            AdminActivity::log(
                AdminActivity::ACTION_DELETED,
                CustomerPaymentMethod::class,
                modelId: $this->deleteMethodId,
                description: "Deleted payment method {$displayName} for {$this->clientInfo['client_name']}",
                oldValues: $deletedMethodData
            );

            $this->successMessage = 'Payment method deleted successfully.';
            $this->cancelDelete();
            $this->loadPaymentMethods();
        } catch (\Exception $e) {
            Log::error('Failed to delete payment method', ['error' => $e->getMessage()]);
            $this->errorMessage = 'Failed to delete payment method: '.$e->getMessage();
            $this->cancelDelete();
        }
    }

    public function render()
    {
        return view('livewire.admin.clients.payment-methods');
    }
}
