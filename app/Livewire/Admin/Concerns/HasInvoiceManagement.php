<?php

// app/Livewire/Admin/Concerns/HasInvoiceManagement.php

namespace App\Livewire\Admin\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Shared invoice loading and selection logic for admin payment wizards.
 *
 * Provides methods to load open invoices for a selected client and manage
 * the selection state (toggle individual, select all).
 *
 * Components using this trait must declare these properties:
 * - array $availableInvoices
 * - array $selectedInvoices
 * - ?array $selectedClient
 *
 * Components must also provide:
 * - PaymentRepository $paymentRepo (injected via boot())
 * - calculateTotals(): void (component-specific total calculation)
 *
 * Note: clearSelection() is intentionally excluded — its reset behavior
 * differs between single payments (conditionally resets paymentAmount)
 * and payment plans (does not). Each component must implement its own.
 *
 * Note: calculateTotals() is intentionally excluded — the calculation
 * logic is fundamentally different between single payments (invoices +
 * engagements + custom amount logic) and payment plans (invoices + plan
 * fee + down payment + monthly payment).
 */
trait HasInvoiceManagement
{
    /**
     * Load open invoices for the selected client.
     */
    protected function loadInvoices(): void
    {
        if (! $this->selectedClient) {
            return;
        }

        try {
            $invoices = $this->paymentRepo->getClientOpenInvoices($this->selectedClient['client_KEY']);
            $this->availableInvoices = $invoices;
        } catch (\Exception $e) {
            Log::error('Failed to load invoices', ['error' => $e->getMessage()]);
            $this->availableInvoices = [];
        }
    }

    /**
     * Toggle selection of a single invoice by its ledger entry key.
     *
     * Adds the invoice to the selection if not already selected, or removes
     * it if currently selected. Recalculates totals after toggling.
     *
     * @param  string  $ledgerEntryKey  The ledger_entry_KEY of the invoice to toggle.
     */
    public function toggleInvoice(string $ledgerEntryKey): void
    {
        if (in_array($ledgerEntryKey, $this->selectedInvoices, true)) {
            $this->selectedInvoices = array_values(array_diff($this->selectedInvoices, [$ledgerEntryKey]));
        } else {
            $this->selectedInvoices[] = $ledgerEntryKey;
        }

        $this->calculateTotals();
    }

    /**
     * Select all available invoices.
     *
     * Replaces the current selection with all invoices from $availableInvoices.
     * Recalculates totals after selecting.
     */
    public function selectAllInvoices(): void
    {
        $this->selectedInvoices = array_map(
            fn ($inv) => (string) $inv['ledger_entry_KEY'],
            $this->availableInvoices
        );

        $this->calculateTotals();
    }

    /**
     * Calculate totals based on current selection.
     *
     * Must be implemented by the consuming component, as the calculation
     * logic differs between single payments and payment plans.
     */
    abstract protected function calculateTotals(): void;
}
