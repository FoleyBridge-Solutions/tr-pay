<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\PaymentPlanCalculator;

/**
 * Unit tests for the simplified PaymentPlanCalculator.
 * 
 * The calculator now supports only 3 plan options:
 * - 3 months: $150 fee
 * - 6 months: $300 fee
 * - 9 months: $450 fee
 */
class PaymentPlanCalculatorTest extends TestCase
{
    protected PaymentPlanCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PaymentPlanCalculator();
    }

    /** @test */
    public function it_returns_correct_fee_for_3_month_plan()
    {
        $fee = $this->calculator->getFee(3);
        
        $this->assertEquals(150.00, $fee);
    }

    /** @test */
    public function it_returns_correct_fee_for_6_month_plan()
    {
        $fee = $this->calculator->getFee(6);
        
        $this->assertEquals(300.00, $fee);
    }

    /** @test */
    public function it_returns_correct_fee_for_9_month_plan()
    {
        $fee = $this->calculator->getFee(9);
        
        $this->assertEquals(450.00, $fee);
    }

    /** @test */
    public function it_returns_zero_fee_for_invalid_duration()
    {
        $this->assertEquals(0.00, $this->calculator->getFee(1));
        $this->assertEquals(0.00, $this->calculator->getFee(2));
        $this->assertEquals(0.00, $this->calculator->getFee(4));
        $this->assertEquals(0.00, $this->calculator->getFee(12));
    }

    /** @test */
    public function it_validates_plan_durations_correctly()
    {
        $this->assertTrue($this->calculator->isValidDuration(3));
        $this->assertTrue($this->calculator->isValidDuration(6));
        $this->assertTrue($this->calculator->isValidDuration(9));
        
        $this->assertFalse($this->calculator->isValidDuration(1));
        $this->assertFalse($this->calculator->isValidDuration(2));
        $this->assertFalse($this->calculator->isValidDuration(4));
        $this->assertFalse($this->calculator->isValidDuration(12));
    }

    /** @test */
    public function it_generates_available_plans_with_correct_calculations()
    {
        $invoiceAmount = 1000.00;
        $plans = $this->calculator->getAvailablePlans($invoiceAmount);
        
        $this->assertCount(3, $plans);
        
        // 3 month plan: $1000 + $150 = $1150, monthly = $383.33
        $this->assertEquals(3, $plans[0]['months']);
        $this->assertEquals(150.00, $plans[0]['fee']);
        $this->assertEquals(1150.00, $plans[0]['total_amount']);
        $this->assertEquals(383.33, $plans[0]['monthly_payment']);
        
        // 6 month plan: $1000 + $300 = $1300, monthly = $216.67
        $this->assertEquals(6, $plans[1]['months']);
        $this->assertEquals(300.00, $plans[1]['fee']);
        $this->assertEquals(1300.00, $plans[1]['total_amount']);
        $this->assertEquals(216.67, $plans[1]['monthly_payment']);
        
        // 9 month plan: $1000 + $450 = $1450, monthly = $161.11
        $this->assertEquals(9, $plans[2]['months']);
        $this->assertEquals(450.00, $plans[2]['fee']);
        $this->assertEquals(1450.00, $plans[2]['total_amount']);
        $this->assertEquals(161.11, $plans[2]['monthly_payment']);
    }

    /** @test */
    public function it_generates_correct_schedule_for_3_month_plan()
    {
        $schedule = $this->calculator->calculateSchedule(1150.00, 0, 3, 'monthly');
        
        $this->assertCount(3, $schedule);
        
        // Verify equal payments (with rounding adjustment on last payment)
        $this->assertEquals(383.33, $schedule[0]['amount']);
        $this->assertEquals(383.33, $schedule[1]['amount']);
        $this->assertEquals(383.34, $schedule[2]['amount']); // Rounding adjustment
        
        // Verify labels
        $this->assertEquals('Payment 1 of 3', $schedule[0]['label']);
        $this->assertEquals('Payment 2 of 3', $schedule[1]['label']);
        $this->assertEquals('Payment 3 of 3', $schedule[2]['label']);
    }

    /** @test */
    public function it_generates_correct_schedule_for_6_month_plan()
    {
        $schedule = $this->calculator->calculateSchedule(1300.00, 0, 6, 'monthly');
        
        $this->assertCount(6, $schedule);
        
        // Verify payment amounts
        $this->assertEquals(216.67, $schedule[0]['amount']);
        $this->assertEquals(216.67, $schedule[5]['amount']); // Last payment may have rounding
    }

    /** @test */
    public function it_generates_correct_schedule_for_9_month_plan()
    {
        $schedule = $this->calculator->calculateSchedule(1450.00, 0, 9, 'monthly');
        
        $this->assertCount(9, $schedule);
        
        // Verify payment amounts
        $this->assertEquals(161.11, $schedule[0]['amount']);
    }

    /** @test */
    public function it_returns_empty_schedule_for_invalid_duration()
    {
        $schedule = $this->calculator->calculateSchedule(1000.00, 0, 12, 'monthly');
        
        $this->assertEmpty($schedule);
    }

    /** @test */
    public function it_returns_empty_schedule_for_zero_amount()
    {
        $schedule = $this->calculator->calculateSchedule(0, 0, 3, 'monthly');
        
        $this->assertEmpty($schedule);
    }

    /** @test */
    public function it_returns_valid_durations()
    {
        $durations = $this->calculator->getValidDurations();
        
        $this->assertEquals([3, 6, 9], $durations);
    }

    /** @test */
    public function calculate_fee_returns_backwards_compatible_array()
    {
        // Test that the old calculateFee method still works for backwards compatibility
        $result = $this->calculator->calculateFee(1000, 0, 6, 'monthly');
        
        $this->assertArrayHasKey('fee_amount', $result);
        $this->assertArrayHasKey('months', $result);
        $this->assertEquals(300.00, $result['fee_amount']);
        $this->assertEquals(6, $result['months']);
    }
}
