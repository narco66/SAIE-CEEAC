<?php

namespace App\Models;

use App\Models\Concerns\GuardsVersion;
use Database\Factories\UserFactory;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'structure_id', 'backup_user_id', 'title', 'locale', 'timezone', 'status', 'source', 'external_id', 'must_change_password', 'notification_preferences', 'is_demo'])]
#[Hidden(['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes'])]
class User extends Authenticatable implements CanResetPasswordContract
{
    /** @use HasFactory<UserFactory> */
    use CanResetPassword, GuardsVersion, HasFactory, HasUuids, Notifiable;

    /**
     * Version initiale (API-04), présente dès la réponse de création.
     *
     * @var array<string, int>
     */
    protected $attributes = ['version' => 1];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'encrypted:array',
            'mfa_enabled_at' => 'datetime',
            'mfa_last_step' => 'integer',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'notification_preferences' => 'array',
            'must_change_password' => 'boolean',
            'is_demo' => 'boolean',
        ];
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(Structure::class);
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(User::class, 'backup_user_id');
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function roleExtensions(): HasMany
    {
        return $this->hasMany(RoleExtension::class);
    }

    public function isActive(): bool
    {
        return ($this->status ?? 'active') === 'active';
    }

    public function initials(): string
    {
        return collect(preg_split('/\s+/', trim($this->name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }
}
