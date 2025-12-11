<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Contact Model
 * 
 * Represents a contact in the Practice CS system
 * 
 * ⚠️⚠️⚠️ CRITICAL: This model reads from Microsoft SQL Server (READ-ONLY!)
 * This database belongs to ANOTHER APPLICATION. We can ONLY READ data.
 */
class Contact extends Model
{
    /**
     * ⚠️ CRITICAL: READ-ONLY connection to external SQL Server database
     */
    protected $connection = 'sqlsrv';
    
    /**
     * The table associated with the model.
     */
    protected $table = 'Contact';

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'contact_KEY';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'contact_KEY',
    ];

    /**
     * Get the clients associated with this contact.
     */
    public function clients()
    {
        return $this->hasMany(Client::class, 'contact_KEY', 'contact_KEY');
    }
}
