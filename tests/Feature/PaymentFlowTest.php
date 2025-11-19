<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Customer;
use App\Models\ProjectAcceptance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use App\Livewire\PaymentFlow;
use Illuminate\Support\Facades\DB;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Set up test database connections
        config(['database.default' => 'sqlite']);
    }

    /** @test */
    public function it_starts_at_step_1_account_type_selection()
    {
        Livewire::test(PaymentFlow::class)
            ->assertSet('currentStep', 1)
            ->assertSee('Select Account Type')
            ->assertSee('Business')
            ->assertSee('Personal');
    }

    /** @test */
    public function it_can_select_business_account_type()
    {
        Livewire::test(PaymentFlow::class)
            ->call('selectAccountType', 'business')
            ->assertSet('accountType', 'business')
            ->assertSet('currentStep', 2)
            ->assertSee('Last 4 Digits of EIN');
    }

    /** @test */
    public function it_can_select_personal_account_type()
    {
        Livewire::test(PaymentFlow::class)
            ->call('selectAccountType', 'personal')
            ->assertSet('accountType', 'personal')
            ->assertSet('currentStep', 2)
            ->assertSee('Last 4 Digits of SSN');
    }

    /** @test */
    public function it_validates_last_4_digits_format()
    {
        Livewire::test(PaymentFlow::class)
            ->set('accountType', 'personal')
            ->set('currentStep', 2)
            ->set('last4', '123') // Only 3 digits
            ->set('lastName', 'Doe')
            ->call('verifyAccount')
            ->assertHasErrors(['last4']);
    }

    /** @test */
    public function it_validates_business_name_is_required()
    {
        Livewire::test(PaymentFlow::class)
            ->set('accountType', 'business')
            ->set('currentStep', 2)
            ->set('last4', '1234')
            ->set('businessName', '')
            ->call('verifyAccount')
            ->assertHasErrors(['businessName']);
    }

    /** @test */
    public function it_can_go_back_from_step_2()
    {
        Livewire::test(PaymentFlow::class)
            ->set('currentStep', 2)
            ->set('accountType', 'business')
            ->call('goBack')
            ->assertSet('currentStep', 1);
    }

    /** @test */
    public function it_shows_project_acceptance_when_exp_projects_exist()
    {
        // This would require mocking the SQL Server connection
        // For now, we test the component state
        
        $component = Livewire::test(PaymentFlow::class);
        
        // Simulate having pending projects
        $component->set('pendingProjects', [
            [
                'engagement_KEY' => 1,
                'client_KEY' => 123,
                'engagement_id' => 'EXP-001',
                'project_name' => 'Test Project',
                'engagement_type' => 'EXPANSION',
                'budget_amount' => 150.00,
                'start_date' => null,
                'end_date' => '2025-12-31',
                'notes' => 'Test notes',
                'group_name' => 'Test Group',
            ]
        ]);
        
        $component->set('hasProjectsToAccept', true)
            ->set('currentStep', 3)
            ->assertSee('Project Acceptance Required')
            ->assertSee('Test Project')
            ->assertSee('$150.00');
    }

    /** @test */
    public function it_requires_checkbox_acceptance_before_accepting_project()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('pendingProjects', [
            [
                'engagement_KEY' => 1,
                'client_KEY' => 123,
                'engagement_id' => 'EXP-001',
                'project_name' => 'Test Project',
                'engagement_type' => 'EXPANSION',
                'budget_amount' => 150.00,
                'group_name' => 'Test Group',
            ]
        ]);
        
        $component->set('hasProjectsToAccept', true)
            ->set('currentStep', 3)
            ->set('currentProjectIndex', 0)
            ->set('acceptTerms', false)
            ->call('acceptProject')
            ->assertHasErrors(['acceptTerms']);
    }

    /** @test */
    public function it_can_accept_a_project_with_checkbox()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('pendingProjects', [
            [
                'engagement_KEY' => 1,
                'client_KEY' => 123,
                'engagement_id' => 'EXP-001',
                'project_name' => 'Test Project',
                'engagement_type' => 'EXPANSION',
                'budget_amount' => 150.00,
                'group_name' => 'Test Group',
            ]
        ]);
        
        $component->set('hasProjectsToAccept', true)
            ->set('currentStep', 3)
            ->set('currentProjectIndex', 0)
            ->set('acceptTerms', true)
            ->call('acceptProject')
            ->assertHasNoErrors();
        
        // Verify project was queued for persistence
        $this->assertCount(1, $component->get('projectsToPersist'));
    }

    /** @test */
    public function it_moves_to_next_project_after_accepting_first()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('pendingProjects', [
            [
                'engagement_KEY' => 1,
                'client_KEY' => 123,
                'engagement_id' => 'EXP-001',
                'project_name' => 'Project 1',
                'engagement_type' => 'EXPANSION',
                'budget_amount' => 150.00,
                'group_name' => 'Test Group',
            ],
            [
                'engagement_KEY' => 2,
                'client_KEY' => 123,
                'engagement_id' => 'EXP-002',
                'project_name' => 'Project 2',
                'engagement_type' => 'EXPANSION',
                'budget_amount' => 200.00,
                'group_name' => 'Test Group',
            ]
        ]);
        
        $component->set('hasProjectsToAccept', true)
            ->set('currentStep', 3)
            ->set('currentProjectIndex', 0)
            ->set('acceptTerms', true)
            ->call('acceptProject')
            ->assertSet('currentProjectIndex', 1)
            ->assertSet('currentStep', 3) // Still on step 3
            ->assertSet('acceptTerms', false); // Checkbox reset for next project
    }

    /** @test */
    public function it_moves_to_invoice_selection_after_all_projects_accepted()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('pendingProjects', [
            [
                'engagement_KEY' => 1,
                'client_KEY' => 123,
                'engagement_id' => 'EXP-001',
                'project_name' => 'Project 1',
                'engagement_type' => 'EXPANSION',
                'budget_amount' => 150.00,
                'group_name' => 'Test Group',
            ]
        ]);
        
        $component->set('clientInfo', [
            'client_KEY' => 123,
            'client_id' => 'TEST',
            'client_name' => 'Test Client',
        ]);
        
        $component->set('hasProjectsToAccept', true)
            ->set('currentStep', 3)
            ->set('currentProjectIndex', 0)
            ->set('acceptTerms', true)
            ->call('acceptProject')
            ->assertSet('currentStep', 4); // Move to invoice selection
    }

    /** @test */
    public function it_can_decline_a_project()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('pendingProjects', [
            [
                'engagement_KEY' => 1,
                'client_KEY' => 123,
                'engagement_id' => 'EXP-001',
                'project_name' => 'Test Project',
                'engagement_type' => 'EXPANSION',
                'budget_amount' => 150.00,
                'group_name' => 'Test Group',
            ]
        ]);
        
        $component->set('hasProjectsToAccept', true)
            ->set('currentStep', 3)
            ->set('currentProjectIndex', 0)
            ->call('declineProject')
            ->assertSet('currentStep', 1); // Return to start
    }

    /** @test */
    public function it_validates_invoice_selection()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('currentStep', 4)
            ->set('selectedInvoices', [])
            ->call('savePaymentInfo')
            ->assertHasErrors(['selectedInvoices']);
    }

    /** @test */
    public function it_calculates_credit_card_fee_correctly()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 100.00)
            ->set('paymentMethod', 'credit_card');
        
        // Trigger the credit card fee calculation
        $component->call('selectPaymentMethod', 'credit_card');
        
        $fee = $component->get('creditCardFee');
        $this->assertEquals(3.00, $fee); // 3% of $100
    }

    /** @test */
    public function it_does_not_charge_fee_for_ach()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 100.00)
            ->call('selectPaymentMethod', 'ach');
        
        $fee = $component->get('creditCardFee');
        $this->assertEquals(0, $fee);
    }

    /** @test */
    public function it_validates_credit_card_details()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentMethod', 'credit_card')
            ->set('cardNumber', '123') // Invalid
            ->set('cardExpiry', '13/25') // Invalid month
            ->set('cardCvv', '12') // Invalid CVV
            ->call('confirmPayment')
            ->assertHasErrors(['cardNumber', 'cardExpiry', 'cardCvv']);
    }

    /** @test */
    public function it_validates_ach_details()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentMethod', 'ach')
            ->set('routingNumber', '12345') // Invalid - must be 9 digits
            ->set('accountNumber', '123') // Invalid - must be 8-17 digits
            ->set('bankName', '')
            ->call('confirmPayment')
            ->assertHasErrors(['routingNumber', 'accountNumber', 'bankName']);
    }

    /** @test */
    public function accepted_projects_are_persisted_after_successful_payment()
    {
        $this->markTestSkipped('Requires MiPaymentChoice mock');
        
        // This test would verify that:
        // 1. Projects are queued in $projectsToPersist
        // 2. Payment is processed successfully
        // 3. persistAcceptedProjects() is called
        // 4. ProjectAcceptance records are created in database
    }

    /** @test */
    public function projects_are_not_persisted_if_payment_fails()
    {
        $this->markTestSkipped('Requires MiPaymentChoice mock');
        
        // This test would verify that:
        // 1. Projects are queued in $projectsToPersist
        // 2. Payment fails
        // 3. persistAcceptedProjects() is NOT called
        // 4. No ProjectAcceptance records are created
        // 5. User can re-attempt payment
    }
}
