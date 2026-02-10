<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectAcceptance extends Model
{
    protected $fillable = [
        'project_engagement_key',
        'client_key',
        'client_group_name',
        'engagement_id',
        'project_name',
        'budget_amount',
        'accepted',
        'accepted_at',
        'accepted_by_ip',
        'acceptance_signature',
        // Payment tracking
        'paid',
        'paid_at',
        'payment_transaction_id',
        // PracticeCS sync status
        'practicecs_updated',
        'new_engagement_type_key',
        'practicecs_updated_at',
        'practicecs_error',
    ];

    protected $casts = [
        'accepted' => 'boolean',
        'accepted_at' => 'datetime',
        'budget_amount' => 'decimal:2',
        'paid' => 'boolean',
        'paid_at' => 'datetime',
        'practicecs_updated' => 'boolean',
        'practicecs_updated_at' => 'datetime',
    ];

    /**
     * Scope for accepted but unpaid projects
     */
    public function scopePending($query)
    {
        return $query->where('accepted', true)->where('paid', false);
    }

    /**
     * Scope for projects that failed to sync to PracticeCS
     */
    public function scopeFailedSync($query)
    {
        return $query->where('paid', true)
            ->where('practicecs_updated', false)
            ->whereNotNull('practicecs_error');
    }
}
