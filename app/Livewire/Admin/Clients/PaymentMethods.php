<?php

// app/Livewire/Admin/Clients/PaymentMethods.php

namespace App\Livewire\Admin\Clients;

use App\Models\Customer;
use App\Repositories\PaymentRepository;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Client Payment Methods page shell.
 *
 * Handles route-level concerns (URL param, layout) and delegates
 * heavy content to the lazy-loaded PaymentMethodsContent child.
 */
#[Layout('layouts::admin')]
class PaymentMethods extends Component
{
    #[Url(as: 'client')]
    public ?string $clientId = null;

    public ?array $clientInfo = null;

    public ?Customer $customer = null;

    protected PaymentRepository $paymentRepo;

    public function boot(PaymentRepository $paymentRepo): void
    {
        $this->paymentRepo = $paymentRepo;
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
            $client = $this->paymentRepo->findClientByClientId($this->clientId);

            if (! $client) {
                return;
            }

            $this->clientInfo = $client;

            $this->customer = Customer::firstOrCreate(
                ['client_id' => $this->clientId],
                [
                    'name' => $client['client_name'],
                ]
            );
        } catch (\Exception $e) {
            Log::error('Failed to load client', ['error' => $e->getMessage()]);
        }
    }

    public function render()
    {
        return view('livewire.admin.clients.payment-methods');
    }
}
