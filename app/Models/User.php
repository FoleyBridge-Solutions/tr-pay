<?php

// app/Models/User.php

namespace App\Models;

use App\Models\Ach\AchReturn;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * User Model
 *
 * Represents an admin user who can access the admin panel.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property bool $is_active
 * @property \Carbon\Carbon|null $last_login_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    // ==================== Relationships ====================

    /**
     * Get the admin activities performed by this user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\AdminActivity, self>
     */
    public function adminActivities(): HasMany
    {
        return $this->hasMany(AdminActivity::class);
    }

    /**
     * Get the ACH returns reviewed by this user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Ach\AchReturn, self>
     */
    public function reviewedAchReturns(): HasMany
    {
        return $this->hasMany(AchReturn::class, 'reviewed_by');
    }

    // ==================== Helper Methods ====================

    /**
     * Check if the user is active.
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Record the user's login time.
     */
    public function recordLogin(): void
    {
        $this->last_login_at = now();
        $this->save();
    }
}
