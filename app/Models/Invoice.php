<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Invoice Model
 *
 * Represents an invoice in the PracticeCS SQL Server database.
 */
class Invoice extends Model
{
    /**
     * Connects to the PracticeCS SQL Server database.
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
