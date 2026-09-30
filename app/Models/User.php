<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_ORGANIZATION_MANAGER = 'organization_manager';
    public const ROLE_TEAM_SUPERVISOR = 'team_supervisor';
    public const ROLE_EMPLOYEE = 'employee';
    public const ROLE_OBSERVER = 'observer';
    public const ROLE_GUEST = 'guest';

    public const DASHBOARD_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_ORGANIZATION_MANAGER,
        self::ROLE_TEAM_SUPERVISOR,
        self::ROLE_EMPLOYEE,
        self::ROLE_OBSERVER,
        self::ROLE_GUEST,
    ];

    protected $fillable = ['name','mobile','email','password','role','is_verified','is_blocked','organization_id'];
    protected $hidden = ['password','remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_verified' => 'boolean', 'is_blocked' => 'boolean'];
    }

    public function documents(): HasMany { return $this->hasMany(TypingDocument::class); }
    public function projects(): HasMany { return $this->hasMany(FarastProject::class); }
    public function knowledge(): HasMany { return $this->hasMany(UserKnowledge::class); }
    public function tickets(): HasMany { return $this->hasMany(Ticket::class); }
    public function wallet(): HasOne { return $this->hasOne(Wallet::class); }
    public function orders(): HasMany { return $this->hasMany(Order::class); }
    public function freeCredits(): HasMany { return $this->hasMany(FreeCredit::class); }
    public function organization(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Organization::class); }
    public function teams(): \Illuminate\Database\Eloquent\Relations\BelongsToMany { return $this->belongsToMany(Team::class)->withPivot('team_role')->withTimestamps(); }
    public function canvaConnection(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(CanvaConnection::class); }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function hasDashboardRole(string $role): bool
    {
        return $this->role === $role;
    }
}
