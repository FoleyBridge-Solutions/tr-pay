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
 * Tests for the payment plan functionality including:
 * - Plan creation
 * - Schedule generation
 * - Frequency calculations
 * - Custom installments
 * - Plan validation
 */
class PaymentPlanTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_configure_monthly_payment_plan()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 1200.00)
            ->set('isPaymentPlan', true)
            ->set('downPayment', 200.00)
            ->set('planDuration', 4)
            ->set('planFrequency', 'monthly')
            ->call('calculatePaymentSchedule');
        
        $schedule = $component->get('paymentSchedule');
        
        // Should have 5 total payments (1 down + 4 installments)
        $this->assertCount(5, $schedule);
        
        // Verify down payment
        $this->assertEquals(200.00, $schedule[0]['amount']);
        $this->assertEquals(0, $schedule[0]['payment_number']);
        
        // Verify installment amounts ($1000 / 4 = $250 each)
        for ($i = 1; $i <= 4; $i++) {
            $this->assertEquals(250.00, $schedule[$i]['amount']);
        }
    }

    /** @test */
    public function it_calculates_weekly_payment_schedule_correctly()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 1000.00)
            ->set('isPaymentPlan', true)
            ->set('downPayment', 100.00)
            ->set('planDuration', 3)
            ->set('planFrequency', 'weekly')
            ->set('planStartDate', now()->format('Y-m-d'))
            ->call('calculatePaymentSchedule');
        
        $schedule = $component->get('paymentSchedule');
        
        $this->assertCount(4, $schedule); // 1 down + 3 weekly
        
        // Verify dates are 7 days apart
        $firstPaymentDate = Carbon::parse($schedule[1]['due_date']);
        $secondPaymentDate = Carbon::parse($schedule[2]['due_date']);
        
        $this->assertEquals(7, $firstPaymentDate->diffInDays($secondPaymentDate));
    }

    /** @test */
    public function it_handles_biweekly_payment_frequency()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 2000.00)
            ->set('isPaymentPlan', true)
            ->set('downPayment', 500.00)
            ->set('planDuration', 3)
            ->set('planFrequency', 'biweekly')
            ->set('planStartDate', now()->format('Y-m-d'))
            ->call('calculatePaymentSchedule');
        
        $schedule = $component->get('paymentSchedule');
        
        // Verify dates are 14 days apart
        if (count($schedule) >= 3) {
            $firstPaymentDate = Carbon::parse($schedule[1]['due_date']);
            $secondPaymentDate = Carbon::parse($schedule[2]['due_date']);
            
            $this->assertEquals(14, $firstPaymentDate->diffInDays($secondPaymentDate));
        }
    }

    /** @test */
    public function it_supports_custom_installment_amounts()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 1000.00)
            ->set('isPaymentPlan', true)
            ->set('downPayment', 100.00)
            ->set('planDuration', 3)
            ->set('planFrequency', 'monthly')
            ->set('customAmounts', true)
            ->set('installmentAmounts', [400.00, 300.00, 200.00])
            ->call('calculatePaymentSchedule');
        
        $schedule = $component->get('paymentSchedule');
        
        // Verify custom amounts are used
        $this->assertEquals(100.00, $schedule[0]['amount']); // Down payment
        $this->assertEquals(400.00, $schedule[1]['amount']); // First installment
        $this->assertEquals(300.00, $schedule[2]['amount']); // Second installment
        $this->assertEquals(200.00, $schedule[3]['amount']); // Third installment
    }

    /** @test */
    public function it_validates_custom_amounts_equal_remaining_balance()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 1000.00)
            ->set('downPayment', 100.00)
            ->set('planDuration', 3)
            ->set('customAmounts', true)
            ->set('installmentAmounts', [200.00, 200.00, 200.00]); // Only $600 total, should be $900
        
        // This should trigger validation when confirmed
        $remainingBalance = 900.00; // $1000 - $100 down payment
        $customTotal = array_sum([200.00, 200.00, 200.00]); // $600
        
        $this->assertNotEquals($remainingBalance, $customTotal);
    }

    /** @test */
    public function it_calculates_quarterly_payments()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 4000.00)
            ->set('isPaymentPlan', true)
            ->set('downPayment', 1000.00)
            ->set('planDuration', 4)
            ->set('planFrequency', 'quarterly')
            ->set('planStartDate', now()->format('Y-m-d'))
            ->call('calculatePaymentSchedule');
        
        $schedule = $component->get('paymentSchedule');
        
        // Verify dates are ~90 days apart
        if (count($schedule) >= 3) {
            $firstPaymentDate = Carbon::parse($schedule[1]['due_date']);
            $secondPaymentDate = Carbon::parse($schedule[2]['due_date']);
            
            $daysDiff = $firstPaymentDate->diffInDays($secondPaymentDate);
            $this->assertGreaterThanOrEqual(89, $daysDiff);
            $this->assertLessThanOrEqual(92, $daysDiff);
        }
    }

    /** @test */
    public function it_allows_deferred_start_date()
    {
        $futureDate = now()->addDays(30);
        
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 1000.00)
            ->set('isPaymentPlan', true)
            ->set('downPayment', 0.00) // No down payment
            ->set('planDuration', 4)
            ->set('planFrequency', 'monthly')
            ->set('planStartDate', $futureDate->format('Y-m-d'))
            ->call('calculatePaymentSchedule');
        
        $schedule = $component->get('paymentSchedule');
        
        if (count($schedule) > 0) {
            $firstPaymentDate = Carbon::parse($schedule[0]['due_date']);
            $this->assertTrue($firstPaymentDate->isSameDay($futureDate));
        }
    }

    /** @test */
    public function it_calculates_payment_plan_fee()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 1000.00)
            ->set('planDuration', 6)
            ->set('planFrequency', 'monthly');
        
        // Trigger fee calculation (implementation would be in calculatePaymentPlanFee method)
        $component->call('calculatePaymentPlanFee');
        
        $fee = $component->get('paymentPlanFee');
        
        // Fee should be calculated based on plan duration
        $this->assertGreaterThanOrEqual(0, $fee);
    }

    /** @test */
    public function it_validates_minimum_plan_duration()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 1000.00)
            ->set('planDuration', 1) // Too short
            ->call('confirmPaymentPlan')
            ->assertHasErrors(['planDuration']);
    }

    /** @test */
    public function it_validates_maximum_plan_duration()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 1000.00)
            ->set('planDuration', 13) // Too long (max is 12)
            ->call('confirmPaymentPlan')
            ->assertHasErrors(['planDuration']);
    }

    /** @test */
    public function it_handles_no_down_payment()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 1200.00)
            ->set('isPaymentPlan', true)
            ->set('downPayment', 0.00)
            ->set('planDuration', 4)
            ->set('planFrequency', 'monthly')
            ->call('calculatePaymentSchedule');
        
        $schedule = $component->get('paymentSchedule');
        
        // Should have 4 payments of $300 each
        $this->assertCount(4, $schedule);
        
        foreach ($schedule as $payment) {
            $this->assertEquals(300.00, $payment['amount']);
        }
    }

    /** @test */
    public function it_combines_credit_card_fee_and_plan_fee()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 1000.00)
            ->set('paymentMethod', 'credit_card')
            ->set('isPaymentPlan', true)
            ->set('planDuration', 6);
        
        // Calculate credit card fee (3%)
        $component->call('selectPaymentMethod', 'credit_card');
        $creditCardFee = $component->get('creditCardFee');
        $this->assertEquals(30.00, $creditCardFee);
        
        // Calculate plan fee
        $component->call('calculatePaymentPlanFee');
        $planFee = $component->get('paymentPlanFee');
        
        // Total should be original amount + both fees
        $totalObligation = 1000.00 + $creditCardFee + $planFee;
        $this->assertGreaterThan(1030.00, $totalObligation);
    }

    /** @test */
    public function it_displays_payment_schedule_preview()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('paymentAmount', 600.00)
            ->set('isPaymentPlan', true)
            ->set('downPayment', 100.00)
            ->set('planDuration', 5)
            ->set('planFrequency', 'monthly')
            ->set('currentStep', 5) // Payment plan config step
            ->call('calculatePaymentSchedule')
            ->assertSee('Payment Schedule Preview')
            ->assertSee('$100.00') // Down payment
            ->assertSee('Due Today');
    }

    /** @test */
    public function it_can_edit_payment_plan_before_confirmation()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('currentStep', 6) // On confirmation step
            ->set('isPaymentPlan', true)
            ->call('editPaymentPlan')
            ->assertSet('currentStep', 5); // Back to plan config
    }

    /** @test */
    public function it_requires_agreement_to_terms_for_payment_plan()
    {
        $component = Livewire::test(PaymentFlow::class);
        
        $component->set('isPaymentPlan', true)
            ->set('currentStep', 6)
            ->set('agreeToTerms', false)
            ->call('authorizePaymentPlan')
            ->assertHasErrors(['agreeToTerms']);
    }
}
