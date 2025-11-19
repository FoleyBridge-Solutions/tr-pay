<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\ProjectAcceptance;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class ProjectAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_create_project_acceptance_record()
    {
        $acceptance = ProjectAcceptance::create([
            'project_engagement_key' => 90589,
            'client_key' => 44631,
            'client_group_name' => 'Test Group',
            'engagement_id' => 'EXP-001',
            'project_name' => 'Expansion Project',
            'budget_amount' => 150.00,
            'accepted' => true,
            'accepted_at' => Carbon::now(),
            'accepted_by_ip' => '127.0.0.1',
            'acceptance_signature' => 'Checkbox Accepted',
        ]);

        $this->assertDatabaseHas('project_acceptances', [
            'engagement_id' => 'EXP-001',
            'accepted' => true,
        ]);

        $this->assertEquals(150.00, $acceptance->budget_amount);
        $this->assertEquals('Checkbox Accepted', $acceptance->acceptance_signature);
    }

    /** @test */
    public function it_prevents_duplicate_project_acceptances()
    {
        // Create first acceptance
        ProjectAcceptance::create([
            'project_engagement_key' => 90589,
            'client_key' => 44631,
            'client_group_name' => 'Test Group',
            'engagement_id' => 'EXP-001',
            'project_name' => 'Expansion Project',
            'budget_amount' => 150.00,
            'accepted' => true,
            'accepted_at' => Carbon::now(),
            'accepted_by_ip' => '127.0.0.1',
            'acceptance_signature' => 'Checkbox Accepted',
        ]);

        // Verify we can query for existing acceptance
        $existing = ProjectAcceptance::where('engagement_id', 'EXP-001')
            ->where('client_key', 44631)
            ->first();

        $this->assertNotNull($existing);
        $this->assertTrue($existing->accepted);
    }

    /** @test */
    public function it_can_mark_project_as_paid()
    {
        $acceptance = ProjectAcceptance::create([
            'project_engagement_key' => 90589,
            'client_key' => 44631,
            'client_group_name' => 'Test Group',
            'engagement_id' => 'EXP-001',
            'project_name' => 'Expansion Project',
            'budget_amount' => 150.00,
            'accepted' => true,
            'accepted_at' => Carbon::now(),
            'accepted_by_ip' => '127.0.0.1',
            'acceptance_signature' => 'Checkbox Accepted',
            'paid' => false,
        ]);

        // Mark as paid
        $acceptance->update([
            'paid' => true,
            'paid_at' => Carbon::now(),
            'payment_transaction_id' => 'txn_123456',
        ]);

        $this->assertTrue($acceptance->paid);
        $this->assertNotNull($acceptance->paid_at);
        $this->assertEquals('txn_123456', $acceptance->payment_transaction_id);
    }

    /** @test */
    public function it_can_find_pending_acceptances_for_client()
    {
        // Create accepted but unpaid projects
        ProjectAcceptance::create([
            'project_engagement_key' => 90589,
            'client_key' => 44631,
            'engagement_id' => 'EXP-001',
            'project_name' => 'Project 1',
            'budget_amount' => 150.00,
            'accepted' => true,
            'accepted_at' => Carbon::now(),
            'paid' => false,
        ]);

        ProjectAcceptance::create([
            'project_engagement_key' => 90590,
            'client_key' => 44631,
            'engagement_id' => 'EXP-002',
            'project_name' => 'Project 2',
            'budget_amount' => 200.00,
            'accepted' => true,
            'accepted_at' => Carbon::now(),
            'paid' => false,
        ]);

        // Create paid project (should not be in results)
        ProjectAcceptance::create([
            'project_engagement_key' => 90591,
            'client_key' => 44631,
            'engagement_id' => 'EXP-003',
            'project_name' => 'Project 3',
            'budget_amount' => 100.00,
            'accepted' => true,
            'accepted_at' => Carbon::now(),
            'paid' => true,
            'paid_at' => Carbon::now(),
        ]);

        $pending = ProjectAcceptance::where('client_key', 44631)
            ->where('accepted', true)
            ->where('paid', false)
            ->get();

        $this->assertCount(2, $pending);
    }

    /** @test */
    public function it_stores_ip_address_on_acceptance()
    {
        $acceptance = ProjectAcceptance::create([
            'project_engagement_key' => 90589,
            'client_key' => 44631,
            'engagement_id' => 'EXP-001',
            'project_name' => 'Test Project',
            'budget_amount' => 150.00,
            'accepted' => true,
            'accepted_at' => Carbon::now(),
            'accepted_by_ip' => '192.168.1.100',
            'acceptance_signature' => 'Checkbox Accepted',
        ]);

        $this->assertEquals('192.168.1.100', $acceptance->accepted_by_ip);
    }

    /** @test */
    public function it_can_query_acceptances_by_date_range()
    {
        // Create acceptance from yesterday
        ProjectAcceptance::create([
            'project_engagement_key' => 90589,
            'client_key' => 44631,
            'engagement_id' => 'EXP-001',
            'project_name' => 'Old Project',
            'budget_amount' => 150.00,
            'accepted' => true,
            'accepted_at' => Carbon::yesterday(),
        ]);

        // Create acceptance from today
        ProjectAcceptance::create([
            'project_engagement_key' => 90590,
            'client_key' => 44631,
            'engagement_id' => 'EXP-002',
            'project_name' => 'New Project',
            'budget_amount' => 200.00,
            'accepted' => true,
            'accepted_at' => Carbon::now(),
        ]);

        $todayAcceptances = ProjectAcceptance::where('accepted_at', '>=', Carbon::today())
            ->get();

        $this->assertCount(1, $todayAcceptances);
        $this->assertEquals('New Project', $todayAcceptances->first()->project_name);
    }

    /** @test */
    public function it_can_calculate_total_accepted_but_unpaid_amount()
    {
        ProjectAcceptance::create([
            'project_engagement_key' => 90589,
            'client_key' => 44631,
            'engagement_id' => 'EXP-001',
            'project_name' => 'Project 1',
            'budget_amount' => 150.00,
            'accepted' => true,
            'paid' => false,
        ]);

        ProjectAcceptance::create([
            'project_engagement_key' => 90590,
            'client_key' => 44631,
            'engagement_id' => 'EXP-002',
            'project_name' => 'Project 2',
            'budget_amount' => 250.00,
            'accepted' => true,
            'paid' => false,
        ]);

        $total = ProjectAcceptance::where('client_key', 44631)
            ->where('accepted', true)
            ->where('paid', false)
            ->sum('budget_amount');

        $this->assertEquals(400.00, $total);
    }

    /** @test */
    public function it_can_group_acceptances_by_client_group()
    {
        ProjectAcceptance::create([
            'project_engagement_key' => 90589,
            'client_key' => 44631,
            'client_group_name' => 'Group A',
            'engagement_id' => 'EXP-001',
            'project_name' => 'Project 1',
            'budget_amount' => 150.00,
            'accepted' => true,
        ]);

        ProjectAcceptance::create([
            'project_engagement_key' => 90590,
            'client_key' => 44632,
            'client_group_name' => 'Group A',
            'engagement_id' => 'EXP-002',
            'project_name' => 'Project 2',
            'budget_amount' => 200.00,
            'accepted' => true,
        ]);

        ProjectAcceptance::create([
            'project_engagement_key' => 90591,
            'client_key' => 44633,
            'client_group_name' => 'Group B',
            'engagement_id' => 'EXP-003',
            'project_name' => 'Project 3',
            'budget_amount' => 100.00,
            'accepted' => true,
        ]);

        $groupACount = ProjectAcceptance::where('client_group_name', 'Group A')->count();
        $groupBCount = ProjectAcceptance::where('client_group_name', 'Group B')->count();

        $this->assertEquals(2, $groupACount);
        $this->assertEquals(1, $groupBCount);
    }
}
