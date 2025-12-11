<?php

namespace Tests\Feature;

use App\Livewire\PaymentFlow;
use App\Repositories\PaymentRepository;
use App\Services\PaymentService;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class PaymentFlowIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_payment_flow_full_process_with_payment_plan()
    {
        // 1. Mock Repository
        $mockRepo = Mockery::mock(PaymentRepository::class);
        $clientInfo = [
            'client_KEY' => '12345',
            'client_id' => 'C100',
            'client_name' => 'Test Business',
            'email' => 'test@example.com'
        ];
        
        $mockRepo->shouldReceive('getClientByTaxIdAndName')
            ->with('1234', 'Test Business')
            ->andReturn($clientInfo);

        $mockRepo->shouldReceive('getGroupedInvoicesForClient')
            ->andReturn([
                'openInvoices' => [
                    [
                        'invoice_number' => 'INV-001',
                        'client_name' => 'Test Business',
                        'open_amount' => 1000.00,
                        'invoice_date' => '2025-01-01',
                        'due_date' => '2025-01-15',
                        'description' => 'Test Invoice'
                    ]
                ],
                'totalBalance' => 1000.00
            ]);

        $this->app->instance(PaymentRepository::class, $mockRepo);

        // 2. Mock Customer Model (Partial to mock trait methods)
        $mockCustomer = Mockery::mock(Customer::class)->makePartial();
        $mockCustomer->shouldReceive('tokenizeCard')
            ->andReturn('token_card_123');
            
        // 3. Mock PaymentService
        $mockService = Mockery::mock(PaymentService::class);
        
        $mockService->shouldReceive('createSetupIntent')
            ->andReturn([
                'success' => true,
                'customer_id' => 'cust_123',
                'client_secret' => 'seti_123'
            ]);
            
        $mockService->shouldReceive('getOrCreateCustomer')
            ->andReturn($mockCustomer);
        
        $mockService->shouldReceive('setupPaymentPlan')
            ->andReturn([
                'success' => true,
                'transaction_id' => 'plan_123',
                'status' => 'active'
            ]);

        $this->app->instance(PaymentService::class, $mockService);

        // 4. Start Livewire Test
        Livewire::test(PaymentFlow::class)
            // Step 1: Account Type
            ->assertSee('Select Account Type')
            ->call('selectAccountType', 'business')
            
            // Step 2: Verify
            ->assertSet('currentStep', 2)
            ->set('last4', '1234')
            ->set('businessName', 'Test Business')
            ->call('verifyAccount')
            
            // Step 3: Invoices
            ->assertSet('currentStep', 3)
            ->call('savePaymentInfo') // Default selects all
            
            // Step 4: Payment Method (Select Plan)
            ->assertSet('currentStep', 4)
            ->call('selectPaymentMethod', 'payment_plan')
            ->assertSet('isPaymentPlan', true)
            
            // Check Default Plan Params
            ->assertSet('planDuration', 3)
            ->assertSet('planFrequency', 'monthly')
            
            // Update Plan
            ->set('planDuration', 6)
            ->call('calculatePaymentPlanFee') 
            
            // Confirm Plan Config
            ->call('confirmPaymentPlan')
            
            // Step 5: Authorize
            ->assertSet('currentStep', 5)
            ->set('agreeToTerms', true)
            ->set('paymentMethod', 'credit_card')
            
            // Now we should see the fee
            ->assertSee('Credit Card Fee')
            
            ->set('cardNumber', '4111 1111 1111 1111')
            ->set('cardExpiry', '12/25')
            ->set('cardCvv', '123')
            ->call('authorizePaymentPlan')
            
            // Step 6: Confirm
            ->assertSet('currentStep', 6)
            // Verify UI Summary shows breakdown
            ->assertSee('Total Obligation:')
            ->call('confirmPayment')
            ->assertHasNoErrors()
            
            // Step 7: Success (Stay on step 6 but show success message)
            ->assertSet('currentStep', 6)
            ->assertSet('paymentProcessed', true)
            ->assertSee('Payment Plan Confirmed');
    }
}
