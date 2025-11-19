<?php

// app/Models/PaymentPlan.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PaymentPlan Model
 * 
 * Represents a payment plan for recurring payments
 * 
 * ⚠️ NOTE: This model uses the DEFAULT database connection (SQLite)
 * NOT the 'sqlsrv' connection which is READ-ONLY!
 */
class PaymentPlan extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_id',
        'client_key',           // Reference to Client.client_KEY from SQL Server
        'plan_id',              // Unique plan identifier
        'total_amount',
        'down_payment',
        'remaining_amount',
        'frequency',            // 'weekly', 'biweekly', 'monthly', etc.
        'duration',             // Number of payments
        'payment_method_id',    // Reference to saved payment method
        'status',              // 'active', 'completed', 'cancelled', 'past_due'
        'start_date',
        'next_payment_date',
        'completed_at',
        'cancelled_at',
        'metadata',            // JSON field for schedule and other data
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'down_payment' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'duration' => 'integer',
            'metadata' => 'array',
            'start_date' => 'date',
            'next_payment_date' => 'date',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the customer for this payment plan
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the payments for this plan
     */
    public function payments()
    {
        return $this->hasMany(Payment::class, 'payment_plan_id');
    }
}
