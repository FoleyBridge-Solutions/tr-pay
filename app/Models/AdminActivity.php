<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * AdminActivity Model
 *
 * Tracks all admin actions for audit logging purposes.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property string $model_type
 * @property int|null $model_id
 * @property string|null $description
 * @property array|null $old_values
 * @property array|null $new_values
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class AdminActivity extends Model
{
    // Action constants
    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_DELETED = 'deleted';

    public const ACTION_CANCELLED = 'cancelled';

    public const ACTION_PAUSED = 'paused';

    public const ACTION_RESUMED = 'resumed';

    public const ACTION_LOGIN = 'login';

    public const ACTION_LOGOUT = 'logout';

    public const ACTION_VIEWED = 'viewed';

    public const ACTION_EXPORTED = 'exported';

    public const ACTION_IMPORTED = 'imported';

    public const ACTION_SKIPPED = 'skipped';

    public const ACTION_SENT = 'sent';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'action',
        'model_type',
        'model_id',
        'description',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    /**
     * Get the user who performed the action.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the related model.
     */
    public function model(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'model_type', 'model_id');
    }

    /**
     * Log an admin activity.
     *
     * @param  string  $action  The action performed (created, updated, etc.)
     * @param  Model|string  $model  The model or model class name
     * @param  int|null  $modelId  The model ID (if not provided from model instance)
     * @param  string|null  $description  Human-readable description
     * @param  array|null  $oldValues  Previous values (for updates)
     * @param  array|null  $newValues  New values (for creates/updates)
     */
    public static function log(
        string $action,
        Model|string $model,
        ?int $modelId = null,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): self {
        $modelType = is_string($model) ? $model : get_class($model);
        $modelId = $modelId ?? ($model instanceof Model ? $model->id : null);

        return self::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'model_type' => $modelType,
            'model_id' => $modelId,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Log a create action.
     */
    public static function logCreated(Model $model, ?string $description = null, ?array $values = null): self
    {
        return self::log(
            self::ACTION_CREATED,
            $model,
            description: $description,
            newValues: $values ?? $model->toArray()
        );
    }

    /**
     * Log an update action.
     */
    public static function logUpdated(Model $model, ?string $description = null, ?array $oldValues = null, ?array $newValues = null): self
    {
        return self::log(
            self::ACTION_UPDATED,
            $model,
            description: $description,
            oldValues: $oldValues ?? $model->getOriginal(),
            newValues: $newValues ?? $model->getChanges()
        );
    }

    /**
     * Log a delete action.
     */
    public static function logDeleted(Model $model, ?string $description = null): self
    {
        return self::log(
            self::ACTION_DELETED,
            $model,
            description: $description,
            oldValues: $model->toArray()
        );
    }

    /**
     * Log a cancellation action.
     */
    public static function logCancelled(Model $model, ?string $description = null): self
    {
        return self::log(
            self::ACTION_CANCELLED,
            $model,
            description: $description
        );
    }

    /**
     * Log a login action.
     */
    public static function logLogin(?string $description = null): self
    {
        return self::log(
            self::ACTION_LOGIN,
            User::class,
            auth()->id(),
            $description ?? 'User logged in'
        );
    }

    /**
     * Log a logout action.
     */
    public static function logLogout(?string $description = null): self
    {
        return self::log(
            self::ACTION_LOGOUT,
            User::class,
            auth()->id(),
            $description ?? 'User logged out'
        );
    }

    /**
     * Scope: Filter by action.
     */
    public function scopeAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope: Filter by model type.
     */
    public function scopeForModel($query, string $modelClass)
    {
        return $query->where('model_type', $modelClass);
    }

    /**
     * Scope: Filter by user.
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Get the action label for display.
     */
    public function getActionLabelAttribute(): string
    {
        return match ($this->action) {
            self::ACTION_CREATED => 'Created',
            self::ACTION_UPDATED => 'Updated',
            self::ACTION_DELETED => 'Deleted',
            self::ACTION_CANCELLED => 'Cancelled',
            self::ACTION_PAUSED => 'Paused',
            self::ACTION_RESUMED => 'Resumed',
            self::ACTION_LOGIN => 'Logged in',
            self::ACTION_LOGOUT => 'Logged out',
            self::ACTION_VIEWED => 'Viewed',
            self::ACTION_EXPORTED => 'Exported',
            self::ACTION_IMPORTED => 'Imported',
            self::ACTION_SKIPPED => 'Skipped',
            default => ucfirst($this->action),
        };
    }

    /**
     * Get the model type short name for display.
     */
    public function getModelNameAttribute(): string
    {
        return class_basename($this->model_type);
    }
}
