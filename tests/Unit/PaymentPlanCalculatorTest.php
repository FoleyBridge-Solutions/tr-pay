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

    public function test_calculate_fee_short_term()
    {
        // $1000 total, $200 down (20% -> Tier 2: Standard Risk)
        // 3 months (Monthly) -> Short Term
        // Rate for Tier 2 / Short = 3% (0.03)
        // Principal = 800
        // Fee = 15 + (800 * 0.03) = 15 + 24 = 39.00
        
        $result = $this->calculator->calculateFee(1000, 200, 3, 'monthly');
        
        $this->assertEquals(39.00, $result['fee_amount']);
        $this->assertEquals('short', $result['duration_tier']);
        $this->assertEquals(2, $result['risk_tier']);
    }

    public function test_calculate_fee_long_term_weekly()
    {
        // $1000 total, $200 down (20% -> Tier 2: Standard Risk)
        // 52 weeks (Weekly) -> Long Term (> 240 days)
        // Rate for Tier 2 / Long = 7% (0.07)
        // Principal = 800
        // Fee = 15 + (800 * 0.07) = 15 + 56 = 71.00
        
        $result = $this->calculator->calculateFee(1000, 200, 52, 'weekly');
        
        $this->assertEquals(71.00, $result['fee_amount']);
        $this->assertEquals('long', $result['duration_tier']);
    }

    public function test_calculate_fee_short_term_weekly()
    {
        // $1000 total, $200 down (20% -> Tier 2: Standard Risk)
        // 12 weeks (Weekly) -> 84 days -> Short Term (< 150 days)
        // Rate for Tier 2 / Short = 3% (0.03)
        // Principal = 800
        // Fee = 15 + (800 * 0.03) = 15 + 24 = 39.00
        
        $result = $this->calculator->calculateFee(1000, 200, 12, 'weekly');
        
        $this->assertEquals(39.00, $result['fee_amount']);
        $this->assertEquals('short', $result['duration_tier']);
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
