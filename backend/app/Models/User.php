<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $table = 'users';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    public const ROLE_ANNOTATOR = 'annotator';

    public const ROLE_REVIEWER = 'reviewer';

    public const ROLE_RESEARCH_ADMIN = 'research_admin';

    public const ANNOTATION_ROLES = [
        self::ROLE_ANNOTATOR,
        self::ROLE_REVIEWER,
        self::ROLE_RESEARCH_ADMIN,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        // role intentionally omitted from mass assignment; assign via explicit admin process.
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
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
            'role' => 'string',
        ];
    }

    public function hasAnnotationRole(): bool
    {
        return in_array($this->role, self::ANNOTATION_ROLES, true);
    }

    public function hasAnyAnnotationRole(array $roles): bool
    {
        return $this->hasAnnotationRole() && in_array($this->role, $roles, true);
    }

    public function isResearchAdmin(): bool
    {
        return $this->role === self::ROLE_RESEARCH_ADMIN;
    }

    public function isReviewer(): bool
    {
        return $this->hasAnyAnnotationRole([self::ROLE_REVIEWER, self::ROLE_RESEARCH_ADMIN]);
    }

    public function savedRecipes(): HasMany
    {
        return $this->hasMany(SavedRecipe::class);
    }

    public function recipeViews(): HasMany
    {
        return $this->hasMany(RecipeView::class);
    }
}
