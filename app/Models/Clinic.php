<?php

namespace App\Models;

use Database\Factories\ClinicFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class Clinic extends Model
{
    /**
     * @use HasFactory<ClinicFactory>
     */
    use HasFactory;

    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'logo_path',
        'phone',
        'email',
        'address',
        'city',
        'timezone',
        'opening_hours',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opening_hours' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The users that belong to the clinic.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role', 'is_active', 'joined_at')
            ->withTimestamps();
    }

    /**
     * Determine whether the clinic has at least one active administrator.
     */
    public function hasActiveAdmin(): bool
    {
        $modelHasRolesTable = config('permission.table_names.model_has_roles', 'model_has_roles');
        $rolePivotKey = config('permission.column_names.role_pivot_key') ?: 'role_id';
        $modelMorphKey = config('permission.column_names.model_morph_key', 'model_id');
        $teamForeignKey = config('permission.column_names.team_foreign_key', 'team_id');

        $rolesTable = config('permission.table_names.roles', 'roles');

        return DB::table('clinic_user')
            // 1. Join the pivot table for users and roles
            ->join($modelHasRolesTable, function (JoinClause $join) use ($modelHasRolesTable, $modelMorphKey, $teamForeignKey): void {
                $join
                    ->on("$modelHasRolesTable.$modelMorphKey", '=', 'clinic_user.user_id')
                    ->where("$modelHasRolesTable.model_type", (new User)->getMorphClass())
                    // Ensure the role assignment is scoped to THIS clinic
                    ->where("$modelHasRolesTable.$teamForeignKey", $this->getKey());
            })
            // 2. Join the roles table
            ->join($rolesTable, "$modelHasRolesTable.$rolePivotKey", '=', "$rolesTable.id")

            // 3. Filter conditions
            ->where('clinic_user.clinic_id', $this->getKey())
            ->where('clinic_user.is_active', true)

            // The role is now global (clinic_id is null in roles table)
            ->whereNull("$rolesTable.$teamForeignKey")
            ->where("$rolesTable.name", 'clinic_admin')

            ->exists();
    }

    /**
     * The services offered by the clinic.
     *
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * The patients registered at the clinic.
     *
     * @return HasMany<Patient, $this>
     */
    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    /**
     * The appointments scheduled at the clinic.
     *
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * The queue tickets generated at the clinic.
     *
     * @return HasMany<QueueTicket, $this>
     */
    public function queueTickets(): HasMany
    {
        return $this->hasMany(QueueTicket::class);
    }

    /**
     * The subscription for the clinic.
     *
     * @return HasOne<Subscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    /**
     * The payment history for the clinic.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The authorized TV displays for the clinic.
     *
     * @return HasMany<DisplayToken, $this>
     */
    public function displayTokens(): HasMany
    {
        return $this->hasMany(DisplayToken::class);
    }
}
