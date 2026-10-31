<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @property bool $is_system
 */
class Role extends SpatieRole
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(static function (Model $model): void {
            if ((bool) $model->getAttribute('is_system')) {
                throw new LogicException('System roles cannot be deleted.');
            }
        });
    }
}
