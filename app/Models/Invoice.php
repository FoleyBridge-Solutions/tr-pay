<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Invoice Model
 * 
 * Represents an invoice in the Practice CS system
 * 
 * ⚠️⚠️⚠️ CRITICAL: This model reads from Microsoft SQL Server (READ-ONLY!)
 * This database belongs to ANOTHER APPLICATION. We can ONLY READ data.
 */
class Invoice extends Model
{
    /**
     * ⚠️ CRITICAL: READ-ONLY connection to external SQL Server database
     */
    protected $connection = 'sqlsrv';
    
    /**
     * The table associated with the model.
     */
    protected $table = 'Invoice';

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
        'ledger_entry_KEY',
        'due_date',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'datetime',
        ];
    }

    /**
     * Get the ledger entry associated with this invoice.
     */
    public function ledgerEntry()
    {
        return $this->belongsTo(LedgerEntry::class, 'ledger_entry_KEY', 'ledger_entry_KEY');
    }
}
