<?php

// app/Models/Payment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Payment Model
 * 
 * Represents a payment transaction processed through this application
 * 
 * ⚠️ NOTE: This model uses the DEFAULT database connection (SQLite)
 * NOT the 'sqlsrv' connection which is READ-ONLY!
 */
class Payment extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_id',
        'client_key',           // Reference to Client.client_KEY from SQL Server
        'transaction_id',        // MiPaymentChoice transaction ID
        'amount',
        'fee',
        'total_amount',
        'payment_method',        // 'credit_card', 'ach', 'check'
        'status',               // 'pending', 'completed', 'failed', 'refunded'
        'description',
        'metadata',             // JSON field for additional data
        'processed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'metadata' => 'array',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the customer that made this payment
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
