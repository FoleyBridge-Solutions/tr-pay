<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use App\Livewire\PaymentFlow;
use Carbon\Carbon;

/**
 * Payment Plan Tests
 * 
 * Tests for the simplified payment plan functionality:
 * - 3 months: $150 fee
 * - 6 months: $300 fee  
 * - 9 months: $450 fee
 * 
 * All plans are monthly payments with no down payment.
 */
class PaymentPlanTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_select_3_month_payment_plan()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 1000.00)
            ->set('isPaymentPlan', true)
            ->call('selectPlanDuration', 3)
            ->call('calculatePaymentSchedule');
        
        $this->assertEquals(3, $component->get('planDuration'));
        $this->assertEquals(150.00, $component->get('paymentPlanFee'));
        
        $schedule = $component->get('paymentSchedule');
        $this->assertCount(3, $schedule);
    }

    /** @test */
    public function it_can_select_6_month_payment_plan()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 1000.00)
            ->set('isPaymentPlan', true)
            ->call('selectPlanDuration', 6)
            ->call('calculatePaymentSchedule');
        
        $this->assertEquals(6, $component->get('planDuration'));
        $this->assertEquals(300.00, $component->get('paymentPlanFee'));
        
        $schedule = $component->get('paymentSchedule');
        $this->assertCount(6, $schedule);
    }

    /** @test */
    public function it_can_select_9_month_payment_plan()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 1000.00)
            ->set('isPaymentPlan', true)
            ->call('selectPlanDuration', 9)
            ->call('calculatePaymentSchedule');
        
        $this->assertEquals(9, $component->get('planDuration'));
        $this->assertEquals(450.00, $component->get('paymentPlanFee'));
        
        $schedule = $component->get('paymentSchedule');
        $this->assertCount(9, $schedule);
    }

    /** @test */
    public function it_rejects_invalid_plan_durations()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 1000.00)
            ->set('isPaymentPlan', true)
            ->call('selectPlanDuration', 12) // Invalid - not 3, 6, or 9
            ->assertHasErrors(['planDuration']);
    }

    /** @test */
    public function it_calculates_equal_monthly_payments()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        // $1000 + $150 fee = $1150 / 3 = $383.33/month
        $component->set('paymentAmount', 1000.00)
            ->set('isPaymentPlan', true)
            ->call('selectPlanDuration', 3)
            ->call('calculatePaymentSchedule');
        
        $schedule = $component->get('paymentSchedule');
        
        $this->assertCount(3, $schedule);
        $this->assertEquals(383.33, $schedule[0]['amount']);
        $this->assertEquals(383.33, $schedule[1]['amount']);
        // Last payment may have rounding adjustment
        $this->assertEquals(383.34, $schedule[2]['amount']);
        
        // Total should equal invoice + fee
        $totalPayments = array_sum(array_column($schedule, 'amount'));
        $this->assertEquals(1150.00, $totalPayments);
    }

    /** @test */
    public function it_calculates_payment_schedule_with_monthly_dates()
    {
        Carbon::setTestNow(Carbon::create(2024, 1, 15));
        
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 1000.00)
            ->set('isPaymentPlan', true)
            ->call('selectPlanDuration', 3)
            ->call('calculatePaymentSchedule');
        
        $schedule = $component->get('paymentSchedule');
        
        // Verify payment labels
        $this->assertEquals('Payment 1 of 3', $schedule[0]['label']);
        $this->assertEquals('Payment 2 of 3', $schedule[1]['label']);
        $this->assertEquals('Payment 3 of 3', $schedule[2]['label']);
        
        Carbon::setTestNow();
    }

    /** @test */
    public function it_shows_available_plan_options_when_payment_plan_selected()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 1000.00)
            ->call('selectPaymentMethod', 'payment_plan');
        
        $availablePlans = $component->get('availablePlans');
        
        $this->assertCount(3, $availablePlans);
        
        // Verify 3 month plan
        $this->assertEquals(3, $availablePlans[0]['months']);
        $this->assertEquals(150.00, $availablePlans[0]['fee']);
        $this->assertEquals(1150.00, $availablePlans[0]['total_amount']);
        
        // Verify 6 month plan
        $this->assertEquals(6, $availablePlans[1]['months']);
        $this->assertEquals(300.00, $availablePlans[1]['fee']);
        $this->assertEquals(1300.00, $availablePlans[1]['total_amount']);
        
        // Verify 9 month plan
        $this->assertEquals(9, $availablePlans[2]['months']);
        $this->assertEquals(450.00, $availablePlans[2]['fee']);
        $this->assertEquals(1450.00, $availablePlans[2]['total_amount']);
    }

    /** @test */
    public function it_defaults_to_3_month_plan()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 1000.00)
            ->call('selectPaymentMethod', 'payment_plan');
        
        $this->assertEquals(3, $component->get('planDuration'));
        $this->assertEquals(150.00, $component->get('paymentPlanFee'));
    }

    /** @test */
    public function it_validates_plan_duration_on_confirm()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 1000.00)
            ->set('isPaymentPlan', true)
            ->set('planDuration', 5) // Invalid duration
            ->call('confirmPaymentPlan')
            ->assertHasErrors(['planDuration']);
    }

    /** @test */
    public function it_allows_valid_plan_durations_on_confirm()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 1000.00)
            ->set('isPaymentPlan', true)
            ->set('planDuration', 6) // Valid duration
            ->call('confirmPaymentPlan')
            ->assertHasNoErrors(['planDuration']);
    }

    /** @test */
    public function it_combines_credit_card_fee_and_plan_fee()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 1000.00)
            ->call('selectPaymentMethod', 'payment_plan');
        
        // Default 3 month plan = $150 fee
        $this->assertEquals(150.00, $component->get('paymentPlanFee'));
        
        // Now set payment method to credit card (plan already selected)
        // Credit card fee should be calculated on invoice + plan fee
        // ($1000 + $150) * 0.03 = $34.50
        $component->set('paymentMethod', 'credit_card');
        $component->call('calculatePaymentPlanFee'); // This should update credit card fee too
        
        $creditCardFee = $component->get('creditCardFee');
        $this->assertEquals(34.50, $creditCardFee);
    }

    /** @test */
    public function it_can_change_payment_method_and_reset_plan()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 1000.00)
            ->call('selectPaymentMethod', 'payment_plan')
            ->call('selectPlanDuration', 9);
        
        $this->assertEquals(9, $component->get('planDuration'));
        $this->assertTrue($component->get('isPaymentPlan'));
        
        // Change payment method
        $component->call('changePaymentMethod');
        
        $this->assertFalse($component->get('isPaymentPlan'));
        $this->assertNull($component->get('paymentMethod'));
    }

    /** @test */
    public function it_requires_terms_agreement_for_payment_plan()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('isPaymentPlan', true)
            ->set('currentStep', 6)
            ->set('agreeToTerms', false)
            ->call('authorizePaymentPlan')
            ->assertHasErrors(['agreeToTerms']);
    }

    /** @test */
    public function it_can_edit_payment_plan_before_confirmation()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('currentStep', 6) // On payment details step
            ->set('isPaymentPlan', true)
            ->call('editPaymentPlan')
            ->assertSet('currentStep', 4); // Back to plan selection (now step 4)
    }

    /** @test */
    public function it_updates_schedule_when_plan_duration_changes()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 900.00)
            ->set('isPaymentPlan', true)
            ->call('selectPlanDuration', 3)
            ->call('calculatePaymentSchedule');
        
        $schedule3 = $component->get('paymentSchedule');
        $this->assertCount(3, $schedule3);
        
        $component->call('selectPlanDuration', 9)
            ->call('calculatePaymentSchedule');
        
        $schedule9 = $component->get('paymentSchedule');
        $this->assertCount(9, $schedule9);
    }

    /** @test */
    public function it_calculates_correct_total_with_large_invoice()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        // Large invoice: $10,000
        $component->set('paymentAmount', 10000.00)
            ->set('isPaymentPlan', true)
            ->call('selectPlanDuration', 9)
            ->call('calculatePaymentSchedule');
        
        // $10,000 + $450 fee = $10,450 / 9 = $1161.11/month
        $schedule = $component->get('paymentSchedule');
        
        $totalPayments = array_sum(array_column($schedule, 'amount'));
        $this->assertEquals(10450.00, round($totalPayments, 2));
    }
}
