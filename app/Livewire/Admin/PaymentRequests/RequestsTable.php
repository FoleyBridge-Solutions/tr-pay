<?php

// app/Livewire/Admin/PaymentRequests/RequestsTable.php

namespace App\Livewire\Admin\PaymentRequests;

use App\Mail\PaymentRequestMail;
use App\Models\AdminActivity;
use App\Models\PaymentRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lazy-loaded payment requests table with filters and actions.
 *
 * Displays a searchable, filterable table of payment requests
 * with revoke and resend capabilities.
 */
#[Lazy]
class RequestsTable extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    /**
     * Selected status filters (multi-select).
     *
     * @var array<int, string>
     */
    #[Url]
    public array $status = [];

    /**
     * Reset pagination when search changes.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Reset pagination when status filter changes.
     */
    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * Revoke a pending payment request.
     */
    public function revoke(int $id): void
    {
        $paymentRequest = PaymentRequest::findOrFail($id);

        if (! $paymentRequest->isUsable()) {
            $this->dispatch('toast', message: 'This payment request cannot be revoked.', type: 'error');

            return;
        }

        $paymentRequest->revoke();

        AdminActivity::log(
            action: AdminActivity::ACTION_CANCELLED,
            model: $paymentRequest,
            description: "Revoked payment request for {$paymentRequest->client_name} ({$paymentRequest->email}) - \${$paymentRequest->amount}"
        );

        $this->dispatch('toast', message: 'Payment request has been revoked.', type: 'success');
    }

    /**
     * Resend the payment request email.
     */
    public function resend(int $id): void
    {
        $paymentRequest = PaymentRequest::findOrFail($id);

        if (! $paymentRequest->isUsable()) {
            $this->dispatch('toast', message: 'This payment request is no longer active.', type: 'error');

            return;
        }

        Mail::to($paymentRequest->email)->send(new PaymentRequestMail($paymentRequest));

        AdminActivity::log(
            action: AdminActivity::ACTION_SENT,
            model: $paymentRequest,
            description: "Resent payment request to {$paymentRequest->client_name} ({$paymentRequest->email}) - \${$paymentRequest->amount}"
        );

        $this->dispatch('toast', message: 'Payment request email has been resent.', type: 'success');
    }

    /**
     * Get filtered payment requests.
     */
    public function getPaymentRequests(): LengthAwarePaginator
    {
        $query = PaymentRequest::query()->with(['sender', 'payment']);

        // Search by client name, client ID, or email
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('client_name', 'like', "%{$this->search}%")
                    ->orWhere('client_id', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%");
            });
        }

        // Filter by computed status
        if (! empty($this->status)) {
            $query->where(function ($q) {
                foreach ($this->status as $status) {
                    $q->orWhere(function ($sub) use ($status) {
                        match ($status) {
                            PaymentRequest::STATUS_PAID => $sub->whereNotNull('paid_at'),
                            PaymentRequest::STATUS_REVOKED => $sub->whereNull('paid_at')->whereNotNull('revoked_at'),
                            PaymentRequest::STATUS_EXPIRED => $sub->whereNull('paid_at')->whereNull('revoked_at')->where('expires_at', '<=', now()),
                            PaymentRequest::STATUS_PENDING => $sub->whereNull('paid_at')->whereNull('revoked_at')->where('expires_at', '>', now()),
                            default => null,
                        };
                    });
                }
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate(20);
    }

    /**
     * Skeleton placeholder shown while component loads.
     */
    public function placeholder(): string
    {
        return <<<'HTML'
        <div>
            <flux:card class="mb-6">
                <div class="flex flex-col md:flex-row gap-4 p-4">
                    <div class="flex-1">
                        <flux:skeleton class="h-10 w-full rounded" />
                    </div>
                    <div class="w-full md:w-48">
                        <flux:skeleton class="h-10 w-full rounded" />
                    </div>
                </div>
            </flux:card>

            <flux:card>
                <flux:skeleton.group animate="shimmer">
                    <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        {{-- Header row --}}
                        <div class="px-4 py-3 flex items-center gap-4">
                            <flux:skeleton.line class="w-24" />
                            <flux:skeleton.line class="w-32" />
                            <flux:skeleton.line class="w-16" />
                            <flux:skeleton.line class="w-16" />
                            <flux:skeleton.line class="w-20" />
                            <flux:skeleton.line class="w-20" />
                            <flux:skeleton.line class="w-20" />
                            <flux:skeleton.line class="w-16" />
                        </div>
                        {{-- Data rows --}}
                        @for ($i = 0; $i < 5; $i++)
                            <div class="px-4 py-3 flex items-center gap-4">
                                <flux:skeleton.line class="w-24" />
                                <flux:skeleton.line class="w-32" />
                                <flux:skeleton.line class="w-16" />
                                <flux:skeleton class="h-5 w-16 rounded-full" />
                                <flux:skeleton.line class="w-20" />
                                <flux:skeleton.line class="w-20" />
                                <flux:skeleton.line class="w-20" />
                                <flux:skeleton.line class="w-16" />
                            </div>
                        @endfor
                    </div>
                </flux:skeleton.group>
            </flux:card>
        </div>
        HTML;
    }

    public function render()
    {
        return view('livewire.admin.payment-requests.requests-table', [
            'paymentRequests' => $this->getPaymentRequests(),
        ]);
    }
}
