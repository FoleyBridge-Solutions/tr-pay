<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\PaymentPlanCalculator;

class PaymentPlanCalculatorTest extends TestCase
{
    protected PaymentPlanCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PaymentPlanCalculator();
    }

    public function test_calculate_fee_with_25_percent_down()
    {
        // $1000 total, $250 down (25%) -> $75 base fee
        // 3 monthly payments = 3 months -> 1.0 duration multiplier
        // Down payment multiplier = 1 - 0.25 = 0.75
        // Final fee = $75 * 1.0 * 0.75 = $56.25
        
        $result = $this->calculator->calculateFee(1000, 250, 3, 'monthly');
        
        $this->assertEquals(56.25, $result['fee_amount']);
        $this->assertEquals(3, $result['months']);
        $this->assertEquals(1.0, $result['duration_multiplier']);
        $this->assertEquals(0.75, $result['down_payment_multiplier']);
        $this->assertEquals(25.0, $result['down_payment_percent']);
    }

    public function test_calculate_fee_with_50_percent_down()
    {
        // $1000 total, $500 down (50%) -> $75 base fee
        // 6 monthly payments = 6 months -> 1.75 duration multiplier
        // Down payment multiplier = 1 - 0.50 = 0.50
        // Final fee = $75 * 1.75 * 0.50 = $65.63
        
        $result = $this->calculator->calculateFee(1000, 500, 6, 'monthly');
        
        $this->assertEquals(65.63, $result['fee_amount']);
        $this->assertEquals(6, $result['months']);
        $this->assertEquals(1.75, $result['duration_multiplier']);
        $this->assertEquals(0.50, $result['down_payment_multiplier']);
        $this->assertEquals(50.0, $result['down_payment_percent']);
    }

    public function test_calculate_fee_with_75_percent_down()
    {
        // $1000 total, $750 down (75%) -> $75 base fee
        // 11 monthly payments = 11 months -> 2.5 duration multiplier (max allowed)
        // Down payment multiplier = 1 - 0.75 = 0.25
        // Final fee = $75 * 2.5 * 0.25 = $46.88
        
        $result = $this->calculator->calculateFee(1000, 750, 11, 'monthly');
        
        $this->assertEquals(46.88, $result['fee_amount']);
        $this->assertEquals(11, $result['months']);
        $this->assertEquals(2.5, $result['duration_multiplier']);
        $this->assertEquals(0.25, $result['down_payment_multiplier']);
        $this->assertEquals(75.0, $result['down_payment_percent']);
    }

    public function test_plans_over_11_months_return_zero_multiplier()
    {
        // $1000 total, $250 down (25%) -> $75 base fee
        // 13 monthly payments = 13 months -> should return 0 multiplier (invalid)
        // Plans over 11 months are not allowed
        
        $result = $this->calculator->calculateFee(1000, 250, 13, 'monthly');
        
        $this->assertEquals(0, $result['duration_multiplier']);
        $this->assertEquals(13, $result['months']);
    }

    public function test_calculate_fee_weekly_with_down_payment()
    {
        // $500 total, $125 down (25%) -> $50 base fee
        // 12 weekly payments = ~3 months (84 days / 30) -> 1.0 duration multiplier
        // Down payment multiplier = 1 - 0.25 = 0.75
        // Final fee = $50 * 1.0 * 0.75 = $37.50
        
        $result = $this->calculator->calculateFee(500, 125, 12, 'weekly');
        
        $this->assertEquals(37.50, $result['fee_amount']);
        $this->assertEquals(3, $result['months']);
        $this->assertEquals(1.0, $result['duration_multiplier']);
        $this->assertEquals(0.75, $result['down_payment_multiplier']);
    }

    public function test_calculate_fee_biweekly_with_high_down()
    {
        // $5000 total, $3750 down (75%) -> $200 base fee
        // 12 biweekly payments = ~6 months (168 days / 30) -> 1.75 duration multiplier
        // Down payment multiplier = 1 - 0.75 = 0.25
        // Final fee = $200 * 1.75 * 0.25 = $87.50
        
        $result = $this->calculator->calculateFee(5000, 3750, 12, 'biweekly');
        
        $this->assertEquals(87.50, $result['fee_amount']);
        $this->assertEquals(6, $result['months']);
        $this->assertEquals(1.75, $result['duration_multiplier']);
        $this->assertEquals(0.25, $result['down_payment_multiplier']);
    }
    
    public function test_get_max_installments()
    {
        $this->assertEquals(52, $this->calculator->getMaxInstallments('weekly'));
        $this->assertEquals(12, $this->calculator->getMaxInstallments('monthly'));
    }

    public function test_schedule_calculation()
    {
        // Finance $100 over 2 months
        $schedule = $this->calculator->calculateSchedule(100, 0, 2, 'monthly');
        
        $this->assertCount(2, $schedule);
        $this->assertEquals(50.00, $schedule[0]['amount']);
        $this->assertEquals(50.00, $schedule[1]['amount']);
    }
}
