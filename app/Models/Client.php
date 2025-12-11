<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Client Model
 * 
 * Represents a client in the Practice CS system
 * 
 * ⚠️⚠️⚠️ CRITICAL: This model reads from Microsoft SQL Server (READ-ONLY!)
 * This database belongs to ANOTHER APPLICATION. We can ONLY READ data.
 * NEVER attempt to create, update, or delete records using this model!
 */
class Client extends Model
{
    /**
     * ⚠️ CRITICAL: This connects to the READ-ONLY SQL Server database
     * owned by another application. NEVER write to this database!
     */
    protected $connection = 'sqlsrv';
    
    /**
     * The table associated with the model.
     */
    protected $table = 'Client';

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'client_KEY';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'client_id',
        'description',
        'contact_KEY',
    ];

    /**
     * Get the contact associated with the client.
     */
    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_KEY', 'contact_KEY');
    }

    /**
     * Get the ledger entries for the client.
     */
    public function ledgerEntries()
    {
        return $this->hasMany(LedgerEntry::class, 'client_KEY', 'client_KEY');
    }

    /**
     * Get the invoices for the client through ledger entries.
     */
    public function invoices()
    {
        return $this->hasManyThrough(
            Invoice::class,
            LedgerEntry::class,
            'client_KEY', // Foreign key on LedgerEntry table
            'ledger_entry_KEY', // Foreign key on Invoice table
            'client_KEY', // Local key on Client table
            'ledger_entry_KEY' // Local key on LedgerEntry table
        );
    }
}
