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
    ];

    protected $casts = [
        'accepted' => 'boolean',
        'accepted_at' => 'datetime',
        'budget_amount' => 'decimal:2',
    ];
}
