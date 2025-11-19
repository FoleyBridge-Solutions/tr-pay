<?php

// app/Models/Customer.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use MiPaymentChoice\Cashier\Traits\Billable;

/**
 * Customer Model
 * 
 * Represents a customer who can make payments through MiPaymentChoice.
 * This model uses the Billable trait from mipaymentchoice-cashier package
 * to handle payment methods, subscriptions, and charges.
 * 
 * ⚠️ IMPORTANT: This model uses the DEFAULT database connection (SQLite)
 * NOT the 'sqlsrv' connection which is READ-ONLY!
 */
class Customer extends Model
{
    use Billable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'client_id',         // Reference to Client.client_id from SQL Server (read-only)
        'client_key',        // Reference to Client.client_KEY from SQL Server (read-only)
        'mpc_customer_id',   // MiPaymentChoice Customer ID (added by migration)
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
