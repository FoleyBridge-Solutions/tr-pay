<?php

// app/Livewire/PaymentFlow/HasInvoiceSelection.php

namespace App\Livewire\PaymentFlow;

/**
 * Trait for invoice selection, sorting, and client grouping functionality.
 *
 * This trait handles:
 * - Loading and displaying invoices
 * - Invoice sorting by column
 * - Individual and bulk invoice selection
 * - Per-client "Pay All" toggles for grouped invoices
 * - Payment amount calculation
 */
trait HasInvoiceSelection
{
    // ==================== Invoice Selection Properties ====================
    // Note: These are declared in the main component, not here
    // $openInvoices, $selectedInvoices, $selectAll, $clientSelectAll,
    // $clientNameMap, $sortBy, $sortDirection, $showRelatedInvoices, $loadingInvoices

    /**
     * Load invoices (called from frontend after skeleton is shown)
     */
    public function loadInvoicesData(): void
    {
        $this->loadClientInvoices();
        $this->loadingInvoices = false;
    }

    /**
     * Load client invoices and balance
     */
    private function loadClientInvoices(): void
    {
        $clientKey = isset($this->clientInfo['clients'])
            ? $this->clientInfo['clients'][0]['client_KEY']
            : $this->clientInfo['client_KEY'];

        $result = $this->paymentRepo->getGroupedInvoicesForClient($clientKey, $this->clientInfo);

        $this->openInvoices = $result['openInvoices'];
        $this->totalBalance = $result['totalBalance'];

        // Sort invoices
        $this->sortInvoices();

        // Pre-select all invoices by default (excluding placeholders)
        $this->selectedInvoices = collect($this->openInvoices)
            ->filter(function ($invoice) {
                return ! isset($invoice['is_placeholder']) || ! $invoice['is_placeholder'];
            })
            ->pluck('invoice_number')
            ->map(fn ($num) => (string) $num)
            ->toArray();
        $this->selectAll = true; // Set toggle to match pre-selected state

        // Initialize per-client toggle states
        $this->updateClientToggleStates();

        $this->calculatePaymentAmount();
    }

    /**
     * Toggle display of related client invoices
     */
    public function toggleRelatedInvoices(): void
    {
        $this->showRelatedInvoices = ! $this->showRelatedInvoices;

        // Preserve current selection before reloading
        $previousSelection = $this->selectedInvoices;

        $this->loadClientInvoices(); // Reload invoices with new setting

        // Restore previous selection (only for invoices that still exist)
        $availableInvoiceNumbers = collect($this->openInvoices)
            ->filter(fn ($inv) => ! isset($inv['is_placeholder']) || ! $inv['is_placeholder'])
            ->pluck('invoice_number')
            ->map(fn ($num) => (string) $num)
            ->toArray();

        $this->selectedInvoices = array_values(array_intersect($previousSelection, $availableInvoiceNumbers));
        $this->updatedSelectedInvoices();
    }

    /**
     * Sort invoices by column
     */
    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        $this->sortInvoices();
    }

    /**
     * Apply sorting to invoices
     */
    private function sortInvoices(): void
    {
        if (empty($this->openInvoices)) {
            return;
        }

        $sortBy = $this->sortBy;
        $direction = $this->sortDirection;

        usort($this->openInvoices, function ($a, $b) use ($sortBy, $direction) {
            $aVal = $a[$sortBy] ?? '';
            $bVal = $b[$sortBy] ?? '';

            // Handle numeric values
            if ($sortBy === 'open_amount') {
                $aVal = (float) $aVal;
                $bVal = (float) $bVal;
            }

            // Handle dates
            if (in_array($sortBy, ['invoice_date', 'due_date'])) {
                // Handle 'N/A' dates by treating them as very old dates for sorting
                $aVal = ($aVal === 'N/A' || empty($aVal)) ? 0 : strtotime($aVal);
                $bVal = ($bVal === 'N/A' || empty($bVal)) ? 0 : strtotime($bVal);
            }

            $result = $aVal <=> $bVal;

            return $direction === 'asc' ? $result : -$result;
        });
    }

    /**
     * Toggle individual invoice selection
     */
    public function toggleInvoice(string $invoiceNumber): void
    {
        // Ensure string type for consistency
        $invoiceNumber = (string) $invoiceNumber;

        // Don't allow selecting placeholder invoices (clients with no actual invoices)
        $invoice = collect($this->openInvoices)->firstWhere('invoice_number', $invoiceNumber);
        if ($invoice && isset($invoice['is_placeholder']) && $invoice['is_placeholder']) {
            return; // Don't select placeholder invoices
        }

        if (in_array($invoiceNumber, $this->selectedInvoices, true)) {
            $this->selectedInvoices = array_values(array_diff($this->selectedInvoices, [$invoiceNumber]));
        } else {
            $this->selectedInvoices[] = $invoiceNumber;
        }

        $this->updatedSelectedInvoices();
    }

    /**
     * Toggle select all invoices
     */
    public function toggleSelectAll(): void
    {
        $selectableInvoices = collect($this->openInvoices)->where(function ($invoice) {
            return ! isset($invoice['is_placeholder']) || ! $invoice['is_placeholder'];
        });

        // Check if currently all are selected
        $allSelected = count($this->selectedInvoices) === $selectableInvoices->count();

        if ($allSelected) {
            // Deselect all
            $this->selectedInvoices = [];
            $this->selectAll = false;
        } else {
            // Select all non-placeholder invoices (cast to string)
            $this->selectedInvoices = $selectableInvoices->pluck('invoice_number')
                ->map(fn ($num) => (string) $num)
                ->toArray();
            $this->selectAll = true;
        }

        $this->updatedSelectedInvoices();
    }

    /**
     * Update selectAll state when selection changes
     */
    public function updatedSelectedInvoices(): void
    {
        // Get all valid invoice numbers from openInvoices (non-placeholder only)
        $validInvoiceNumbers = collect($this->openInvoices)
            ->filter(fn ($invoice) => ! isset($invoice['is_placeholder']) || ! $invoice['is_placeholder'])
            ->pluck('invoice_number')
            ->map(fn ($num) => (string) $num)
            ->toArray();

        // Sanitize selectedInvoices to only include valid invoice numbers
        $this->selectedInvoices = array_values(array_intersect($this->selectedInvoices, $validInvoiceNumbers));

        $selectableCount = count($validInvoiceNumbers);

        $this->selectAll = count($this->selectedInvoices) > 0 && count($this->selectedInvoices) === $selectableCount;

        // Update per-client toggle states
        $this->updateClientToggleStates();

        $this->calculatePaymentAmount();
    }

    /**
     * Update all client toggle states based on current selection
     */
    private function updateClientToggleStates(): void
    {
        $invoicesByClient = collect($this->openInvoices)->groupBy('client_name');

        foreach ($invoicesByClient as $clientName => $clientInvoices) {
            // Sanitize client name for use as array key
            $sanitizedKey = $this->sanitizeClientKey($clientName);

            // Store mapping from sanitized key to real client name
            $this->clientNameMap[$sanitizedKey] = $clientName;

            $selectableInvoices = $clientInvoices->where(function ($invoice) {
                return ! isset($invoice['is_placeholder']) || ! $invoice['is_placeholder'];
            });

            // Cast invoice numbers to strings for consistent comparison
            $clientInvoiceNumbers = $selectableInvoices->pluck('invoice_number')
                ->map(fn ($num) => (string) $num)
                ->toArray();

            if (! empty($clientInvoiceNumbers)) {
                $selectedCount = count(array_intersect($this->selectedInvoices, $clientInvoiceNumbers));
                $this->clientSelectAll[$sanitizedKey] = $selectedCount === count($clientInvoiceNumbers);
            }
        }
    }

    /**
     * Handle selectAll toggle via wire:model
     */
    public function updatedSelectAll(bool $value): void
    {
        $this->toggleSelectAll();
    }

    /**
     * Sanitize client name to create a valid array key for wire:model
     * Uses md5 hash to handle spaces and special characters
     */
    private function sanitizeClientKey(string $clientName): string
    {
        return md5($clientName);
    }

    /**
     * Toggle all invoices for a specific client
     */
    public function toggleClientSelectAll(string $clientKey): void
    {
        // Get real client name from map
        $clientName = $this->clientNameMap[$clientKey] ?? null;

        if (! $clientName) {
            // Key not found, rebuild the map and try again
            $this->updateClientToggleStates();
            $clientName = $this->clientNameMap[$clientKey] ?? null;

            if (! $clientName) {
                return; // Still invalid, abort
            }
        }

        // Get all selectable invoices for this client (cast to strings)
        $clientInvoices = collect($this->openInvoices)
            ->where('client_name', $clientName)
            ->where(function ($invoice) {
                return ! isset($invoice['is_placeholder']) || ! $invoice['is_placeholder'];
            });

        $clientInvoiceNumbers = $clientInvoices->pluck('invoice_number')
            ->map(fn ($num) => (string) $num)
            ->toArray();

        // Check current state
        $allSelected = ! empty($clientInvoiceNumbers) &&
                      count(array_intersect($this->selectedInvoices, $clientInvoiceNumbers)) === count($clientInvoiceNumbers);

        if ($allSelected) {
            // Deselect all client invoices
            $this->selectedInvoices = array_values(array_diff($this->selectedInvoices, $clientInvoiceNumbers));
        } else {
            // Select all client invoices
            $this->selectedInvoices = array_values(array_unique(array_merge($this->selectedInvoices, $clientInvoiceNumbers)));
        }

        $this->updatedSelectedInvoices();
    }

    /**
     * Update per-client toggle states when changed via wire:model
     * Livewire calls this automatically when clientSelectAll.{sanitizedKey} changes
     */
    public function updatedClientSelectAll(bool $value, string $sanitizedKey): void
    {
        // Get real client name from map
        $clientName = $this->clientNameMap[$sanitizedKey] ?? null;

        if (! $clientName) {
            // Key not found, rebuild the map and try again
            $this->updateClientToggleStates();
            $clientName = $this->clientNameMap[$sanitizedKey] ?? null;

            if (! $clientName) {
                return; // Still invalid, abort
            }
        }

        // Get all selectable invoices for this client (cast to string)
        $clientInvoices = collect($this->openInvoices)
            ->where('client_name', $clientName)
            ->where(function ($invoice) {
                return ! isset($invoice['is_placeholder']) || ! $invoice['is_placeholder'];
            });

        $clientInvoiceNumbers = $clientInvoices->pluck('invoice_number')
            ->map(fn ($num) => (string) $num)
            ->toArray();

        if ($value) {
            // Select all client invoices
            $this->selectedInvoices = array_values(array_unique(array_merge($this->selectedInvoices, $clientInvoiceNumbers)));
        } else {
            // Deselect all client invoices
            $this->selectedInvoices = array_values(array_diff($this->selectedInvoices, $clientInvoiceNumbers));
        }

        $this->updatedSelectedInvoices();
    }

    /**
     * Calculate payment amount based on selected invoices
     */
    public function calculatePaymentAmount(): void
    {
        $total = 0;

        foreach ($this->openInvoices as $invoice) {
            if (in_array($invoice['invoice_number'], $this->selectedInvoices)) {
                $total += (float) $invoice['open_amount'];
            }
        }

        $this->paymentAmount = $total;
    }

    /**
     * Step 3: Save payment information and proceed to payment method
     */
    public function savePaymentInfo(): void
    {
        // Validate that at least one invoice is selected
        if (count($this->selectedInvoices) === 0) {
            $this->addError('selectedInvoices', 'Please select at least one invoice to pay.');

            return;
        }

        // Calculate max allowed payment amount
        $selectedTotal = collect($this->openInvoices)
            ->whereIn('invoice_number', $this->selectedInvoices)
            ->sum('open_amount');

        $this->validate([
            'paymentAmount' => 'required|numeric|min:0.01|max:'.$selectedTotal,
        ], [
            'paymentAmount.required' => 'Please enter a payment amount',
            'paymentAmount.min' => 'Payment amount must be at least $0.01',
            'paymentAmount.max' => 'Payment amount cannot exceed the selected invoices total ($'.number_format($selectedTotal, 2).')',
        ]);

        $this->goToStep(Steps::PAYMENT_METHOD);
    }
}
