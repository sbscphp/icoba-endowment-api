<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return MorphToMany<Admin, $this>
     */
    public function admins(): MorphToMany
    {
        return $this->morphedByMany(
            Admin::class,
            'model',
            config('permission.table_names.model_has_roles'),
            config('permission.column_names.role_pivot_key', 'role_id'),
            config('permission.column_names.model_morph_key', 'model_id')
        );
    }
}
