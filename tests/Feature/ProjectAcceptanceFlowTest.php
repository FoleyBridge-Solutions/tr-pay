<?php

namespace Tests\Feature;

use App\Livewire\PaymentFlow;
use App\Models\ProjectAcceptance;
use App\Repositories\PaymentRepository;
use App\Services\PaymentPlanCalculator;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ProjectAcceptanceFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_client_with_pending_projects_sees_acceptance_screen()
    {
        // Mock Repository
        $mockRepo = Mockery::mock(PaymentRepository::class);
        
        // Client Info
        $clientInfo = [
            'client_KEY' => '12345',
            'client_id' => 'C100',
            'client_name' => 'Test Business',
            'email' => 'test@example.com',
            'clients' => [
                ['client_KEY' => '12345', 'client_name' => 'Test Business']
            ]
        ];
        
        $mockRepo->shouldReceive('getClientByTaxIdAndName')
            ->andReturn($clientInfo);

        // Mock Pending Projects
        $pendingProjects = [
            [
                'engagement_KEY' => 101,
                'engagement_id' => 'PROJ-001',
                'project_name' => 'Website Redesign',
                'engagement_type' => 'EXP - Web Dev',
                'budget_amount' => 5000.00,
                'start_date' => '2025-01-01',
                'end_date' => '2025-03-01',
                'notes' => 'Full redesign',
                'client_KEY' => '12345',
                'client_name' => 'Test Business',
                'group_name' => 'Group A'
            ]
        ];

        $mockRepo->shouldReceive('getPendingProjectsForClientGroup')
            ->with('12345')
            ->andReturn($pendingProjects);

        // Mock Invoices (called after acceptance)
        $mockRepo->shouldReceive('getGroupedInvoicesForClient')
            ->andReturn([
                'openInvoices' => [],
                'totalBalance' => 0
            ]);

        $this->app->instance(PaymentRepository::class, $mockRepo);
        
        // Mock other services
        $this->app->instance(PaymentService::class, Mockery::mock(PaymentService::class));
        $this->app->instance(PaymentPlanCalculator::class, Mockery::mock(PaymentPlanCalculator::class));

        Livewire::test(PaymentFlow::class)
            // Step 1
            ->call('selectAccountType', 'business')
            
            // Step 2
            ->set('last4', '1234')
            ->set('businessName', 'Test Business')
            ->call('verifyAccount')
            
            // Should go to Step 3 (Project Acceptance)
            ->assertSet('currentStep', 3)
            ->assertSet('hasProjectsToAccept', true)
            ->assertSee('Project Acceptance Required')
            ->assertSee('Website Redesign')
            ->assertSee('$5,000.00')
            
            // Try to accept without signature
            ->call('acceptProject')
            ->assertHasErrors(['acceptanceSignature'])
            
            // Accept with signature
            ->set('acceptanceSignature', 'John Doe')
            ->call('acceptProject')
            
            // Should move to Step 4 (Invoices)
            ->assertSet('currentStep', 4);
            
        // Verify database
        $this->assertDatabaseHas('project_acceptances', [
            'project_engagement_key' => 101,
            'engagement_id' => 'PROJ-001',
            'budget_amount' => 5000.00,
            'accepted' => true,
            'acceptance_signature' => 'John Doe'
        ]);
    }

    public function test_client_without_pending_projects_skips_acceptance()
    {
        $mockRepo = Mockery::mock(PaymentRepository::class);
        
        $clientInfo = [
            'client_KEY' => '12345',
            'client_id' => 'C100',
            'client_name' => 'Test Business',
            'clients' => [['client_KEY' => '12345']]
        ];
        
        $mockRepo->shouldReceive('getClientByTaxIdAndName')->andReturn($clientInfo);
        
        // No pending projects
        $mockRepo->shouldReceive('getPendingProjectsForClientGroup')->andReturn([]);
        
        $mockRepo->shouldReceive('getGroupedInvoicesForClient')
            ->andReturn(['openInvoices' => [], 'totalBalance' => 0]);

        $this->app->instance(PaymentRepository::class, $mockRepo);
        $this->app->instance(PaymentService::class, Mockery::mock(PaymentService::class));
        $this->app->instance(PaymentPlanCalculator::class, Mockery::mock(PaymentPlanCalculator::class));

        Livewire::test(PaymentFlow::class)
            ->call('selectAccountType', 'business')
            ->set('last4', '1234')
            ->set('businessName', 'Test Business')
            ->call('verifyAccount')
            
            // Should skip to Step 4 (Invoices)
            ->assertSet('currentStep', 4)
            ->assertSet('hasProjectsToAccept', false);
    }
    
    public function test_accepted_project_appears_as_invoice()
    {
        $mockRepo = Mockery::mock(PaymentRepository::class);
        
        $clientInfo = [
            'client_KEY' => '12345',
            'client_id' => 'C100',
            'client_name' => 'Test Business',
            'clients' => [['client_KEY' => '12345']]
        ];
        
        $mockRepo->shouldReceive('getClientByTaxIdAndName')->andReturn($clientInfo);
        
        $pendingProjects = [
            [
                'engagement_KEY' => 101,
                'engagement_id' => 'PROJ-001',
                'project_name' => 'Website Redesign',
                'engagement_type' => 'EXP - Web Dev',
                'budget_amount' => 5000.00,
                'start_date' => '2025-01-01',
                'end_date' => '2025-03-01',
                'notes' => 'Full redesign',
                'client_KEY' => '12345',
                'client_name' => 'Test Business'
            ]
        ];

        $mockRepo->shouldReceive('getPendingProjectsForClientGroup')->andReturn($pendingProjects);
        
        $mockRepo->shouldReceive('getGroupedInvoicesForClient')
            ->andReturn(['openInvoices' => [], 'totalBalance' => 0]);

        $this->app->instance(PaymentRepository::class, $mockRepo);
        $this->app->instance(PaymentService::class, Mockery::mock(PaymentService::class));
        $this->app->instance(PaymentPlanCalculator::class, Mockery::mock(PaymentPlanCalculator::class));

        Livewire::test(PaymentFlow::class)
            ->call('selectAccountType', 'business')
            ->set('last4', '1234')
            ->set('businessName', 'Test Business')
            ->call('verifyAccount')
            ->set('acceptanceSignature', 'John Doe')
            ->call('acceptProject')
            
            // Check that invoice list contains the project
            ->assertSet('currentStep', 4)
            ->assertSee('Website Redesign') // Description
            ->assertSee('5,000.00'); // Amount
    }
}
