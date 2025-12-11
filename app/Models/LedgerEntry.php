<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * LedgerEntry Model
 * 
 * Represents a ledger entry (invoice, payment, etc.) in Practice CS
 * 
 * ⚠️⚠️⚠️ CRITICAL: This model reads from Microsoft SQL Server (READ-ONLY!)
 * This database belongs to ANOTHER APPLICATION. We can ONLY READ data.
 */
class LedgerEntry extends Model
{
    /**
     * ⚠️ CRITICAL: READ-ONLY connection to external SQL Server database
     */
    protected $connection = 'sqlsrv';
    
    /**
     * The table associated with the model.
     */
    protected $table = 'Ledger_Entry';

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'ledger_entry_KEY';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'client_KEY',
        'entry_number',
        'entry_date',
        'amount',
        'ledger_entry_type_KEY',
        'posted__staff_KEY',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'entry_date' => 'datetime',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * Get the client that owns the ledger entry.
     */
    public function client()
    {
        return $this->belongsTo(Client::class, 'client_KEY', 'client_KEY');
    }

    /**
     * Get the invoice associated with this ledger entry.
     */
    public function invoice()
    {
        return $this->hasOne(Invoice::class, 'ledger_entry_KEY', 'ledger_entry_KEY');
    }
}
