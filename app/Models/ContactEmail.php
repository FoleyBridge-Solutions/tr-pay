<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ContactEmail Model
 *
 * Represents a contact email address in the PracticeCS SQL Server database.
 * Each Contact can have multiple email addresses (e.g., "E-mail", "E-mail 2").
 * The primary email is determined by the Contact's primary__contact_email_type_KEY.
 *
 * @property int $contact_email_KEY
 * @property int $contact_KEY
 * @property int $contact_email_type_KEY
 * @property string $email
 * @property string|null $display_as
 * @property \DateTime|null $create_date_utc
 * @property \DateTime|null $update_date_utc
 */
class ContactEmail extends Model
{
    /**
     * Connects to the PracticeCS SQL Server database.
     */
    protected $connection = 'sqlsrv';

    /**
     * The table associated with the model.
     */
    protected $table = 'Contact_Email';

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'contact_email_KEY';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'contact_KEY',
        'contact_email_type_KEY',
        'email',
        'display_as',
    ];

    /**
     * Get the contact that owns this email.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_KEY', 'contact_KEY');
    }
}
