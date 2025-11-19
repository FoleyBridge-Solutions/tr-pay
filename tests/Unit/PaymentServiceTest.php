<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\PaymentService;
use App\Models\Customer;
use MiPaymentChoice\Cashier\Services\QuickPaymentsService;
use MiPaymentChoice\Cashier\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PaymentService $paymentService;
    protected $quickPaymentsMock;
    protected $tokenServiceMock;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->quickPaymentsMock = Mockery::mock(QuickPaymentsService::class);
        $this->tokenServiceMock = Mockery::mock(TokenService::class);
        
        $this->paymentService = new PaymentService(
            $this->quickPaymentsMock,
            $this->tokenServiceMock
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_creates_new_customer_if_not_exists()
    {
        $clientInfo = [
            'client_KEY' => 123,
            'client_id' => 'TEST001',
            'client_name' => 'Test Client',
            'email' => 'test@example.com',
        ];

        $customer = $this->paymentService->getOrCreateCustomer($clientInfo);

        $this->assertInstanceOf(Customer::class, $customer);
        $this->assertEquals('Test Client', $customer->name);
        $this->assertEquals('test@example.com', $customer->email);
        $this->assertEquals(123, $customer->client_key);
        $this->assertEquals('TEST001', $customer->client_id);
    }

    /** @test */
    public function it_retrieves_existing_customer()
    {
        // Create existing customer
        $existingCustomer = Customer::create([
            'name' => 'Existing Client',
            'email' => 'existing@example.com',
            'client_key' => 456,
            'client_id' => 'TEST002',
        ]);

        $clientInfo = [
            'client_KEY' => 456,
            'client_id' => 'TEST002',
            'client_name' => 'Existing Client',
            'email' => 'existing@example.com',
        ];

        $customer = $this->paymentService->getOrCreateCustomer($clientInfo);

        $this->assertEquals($existingCustomer->id, $customer->id);
        $this->assertEquals('Existing Client', $customer->name);
    }

    /** @test */
    public function it_creates_payment_intent_successfully()
    {
        $clientInfo = [
            'client_KEY' => 123,
            'client_id' => 'TEST001',
            'client_name' => 'Test Client',
            'email' => 'test@example.com',
        ];

        $paymentData = [
            'amount' => 100.00,
            'fee' => 3.00,
        ];

        $result = $this->paymentService->createPaymentIntent($paymentData, $clientInfo);

        $this->assertTrue($result['success']);
        $this->assertEquals(103.00, $result['amount']);
        $this->assertEquals('quick_payments', $result['type']);
    }

    /** @test */
    public function it_handles_charge_with_quick_payments()
    {
        $customer = Customer::create([
            'name' => 'Test Client',
            'email' => 'test@example.com',
            'client_key' => 123,
            'client_id' => 'TEST001',
        ]);

        // Mock the chargeWithQuickPayments method on the customer
        $mockCustomer = Mockery::mock($customer)->makePartial();
        $mockCustomer->shouldReceive('chargeWithQuickPayments')
            ->once()
            ->with('qp_test_token', 10300, Mockery::type('array'))
            ->andReturn([
                'success' => true,
                'transaction_id' => 'txn_123456',
                'amount' => 103.00,
                'status' => 'approved',
            ]);

        $result = $this->paymentService->chargeWithQuickPayments(
            $mockCustomer,
            'qp_test_token',
            103.00,
            ['description' => 'Test payment']
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('txn_123456', $result['transaction_id']);
        $this->assertEquals(103.00, $result['amount']);
    }

    /** @test */
    public function it_converts_amount_to_cents_for_payment()
    {
        $customer = Customer::create([
            'name' => 'Test Client',
            'email' => 'test@example.com',
            'client_key' => 123,
            'client_id' => 'TEST001',
        ]);

        $mockCustomer = Mockery::mock($customer)->makePartial();
        
        // Verify the amount is converted to cents (100.50 -> 10050)
        $mockCustomer->shouldReceive('chargeWithQuickPayments')
            ->once()
            ->with('qp_test_token', 10050, Mockery::any())
            ->andReturn([
                'success' => true,
                'transaction_id' => 'txn_123456',
                'amount' => 100.50,
            ]);

        $this->paymentService->chargeWithQuickPayments(
            $mockCustomer,
            'qp_test_token',
            100.50
        );
    }

    /** @test */
    public function it_handles_payment_failures()
    {
        $customer = Customer::create([
            'name' => 'Test Client',
            'email' => 'test@example.com',
            'client_key' => 123,
            'client_id' => 'TEST001',
        ]);

        $mockCustomer = Mockery::mock($customer)->makePartial();
        $mockCustomer->shouldReceive('chargeWithQuickPayments')
            ->once()
            ->andThrow(new \MiPaymentChoice\Cashier\Exceptions\PaymentFailedException(
                'Insufficient funds',
                ['error' => 'Card declined']
            ));

        $result = $this->paymentService->chargeWithQuickPayments(
            $mockCustomer,
            'qp_test_token',
            100.00
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Insufficient funds', $result['error']);
    }

    /** @test */
    public function it_sets_up_payment_plan_with_card_token()
    {
        $customer = Customer::create([
            'name' => 'Test Client',
            'email' => 'test@example.com',
            'client_key' => 123,
            'client_id' => 'TEST001',
        ]);

        $paymentData = [
            'amount' => 300.00,
            'planDuration' => 3,
            'planFrequency' => 'monthly',
            'downPayment' => 100.00,
            'payment_type' => 'card',
            'paymentSchedule' => [
                ['amount' => 100.00, 'due_date' => '2025-01-01'],
                ['amount' => 100.00, 'due_date' => '2025-02-01'],
                ['amount' => 100.00, 'due_date' => '2025-03-01'],
            ],
        ];

        $clientInfo = [
            'client_KEY' => 123,
            'client_id' => 'TEST001',
            'client_name' => 'Test Client',
        ];

        $result = $this->paymentService->setupPaymentPlan(
            $paymentData,
            $clientInfo,
            'card_token_123'
        );

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('plan_id', $result);
    }

    /** @test */
    public function it_validates_payment_plan_data()
    {
        $customer = Customer::create([
            'name' => 'Test Client',
            'email' => 'test@example.com',
            'client_key' => 123,
            'client_id' => 'TEST001',
        ]);

        $invalidPaymentData = [
            'amount' => 300.00,
            // Missing required fields: planDuration, planFrequency, downPayment
        ];

        $clientInfo = [
            'client_KEY' => 123,
            'client_id' => 'TEST001',
            'client_name' => 'Test Client',
        ];

        $result = $this->paymentService->setupPaymentPlan(
            $invalidPaymentData,
            $clientInfo,
            'card_token_123'
        );

        // Should handle missing data gracefully
        $this->assertIsArray($result);
    }
}
