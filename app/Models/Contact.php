<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contact Model
 *
 * Represents a contact in the PracticeCS SQL Server database.
 *
 * @property int $contact_KEY
 * @property int|null $primary__contact_email_type_KEY
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
        'primary__contact_email_type_KEY',
    ];

    /**
     * Get the clients associated with this contact.
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'contact_KEY', 'contact_KEY');
    }

    /**
     * Get all email addresses for this contact.
     */
    public function emails(): HasMany
    {
        return $this->hasMany(ContactEmail::class, 'contact_KEY', 'contact_KEY');
    }

    /**
     * Get the primary email address for this contact.
     *
     * Uses the contact's primary__contact_email_type_KEY to find the
     * matching Contact_Email record. Falls back to the first email
     * if no primary type is set.
     *
     * @return string|null The primary email address, or null if none found
     */
    public function primaryEmail(): ?string
    {
        if ($this->primary__contact_email_type_KEY) {
            $email = $this->emails()
                ->where('contact_email_type_KEY', $this->primary__contact_email_type_KEY)
                ->first();

            if ($email) {
                return $email->email;
            }
        }

        // Fall back to the first email if no primary type is set
        $firstEmail = $this->emails()->first();

        return $firstEmail?->email;
    }
}
