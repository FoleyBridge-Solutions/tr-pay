<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Contact Model
 *
 * Represents a contact in the PracticeCS SQL Server database.
 */
class Contact extends Model
{
    /**
     * Connects to the PracticeCS SQL Server database.
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
