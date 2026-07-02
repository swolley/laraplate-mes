<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Overrides\Model;
use Modules\ERP\Concerns\BelongsToCompany;
use Modules\ERP\Enums\ERPTables;
use Modules\MES\Database\Factories\WorkCenterFactory;
use Modules\MES\Enums\MESTables;
use Modules\MES\Enums\WorkCenterType;
use Override;

/**
 * @mixin \Eloquent
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property WorkCenterType $type
 * @property string $capacity_per_hour
 * @property string $capacity_uom
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @mixin IdeHelperWorkCenter
 */
final class WorkCenter extends Model
{
    use BelongsToCompany;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_work_centers';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'type',
        'capacity_per_hour',
        'capacity_uom',
        'is_active',
    ];

    /**
     * Validation rules for create and update operations.
     *
     * @return array<string, array<string, list<string>>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();

        $table = MESTables::WorkCenters->value;

        $rules['create'] = array_merge($rules['create'], [
            'company_id' => ['required', 'integer', 'exists:' . ERPTables::Companies->value . ',id'],
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', WorkCenterType::validationRule()],
            'capacity_per_hour' => ['required', 'numeric', 'min:0'],
            'capacity_uom' => ['required', 'string', 'max:16'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $rules['update'] = array_merge($rules['update'], [
            'code' => ['sometimes', 'string', 'max:32'],
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', WorkCenterType::validationRule()],
            'capacity_per_hour' => ['sometimes', 'numeric', 'min:0'],
            'capacity_uom' => ['sometimes', 'string', 'max:16'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return $rules;
    }

    /**
     * Calendar availability slots associated with this work center.
     *
     * @return HasMany<WorkCenterCalendar, $this>
     */
    public function calendar(): HasMany
    {
        return $this->hasMany(WorkCenterCalendar::class);
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory<WorkCenter>
     */
    protected static function newFactory(): Factory
    {
        return WorkCenterFactory::new();
    }

    /**
     * Scope to filter only active work centers.
     *
     * @param  Builder<WorkCenter>  $query
     * @return Builder<WorkCenter>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'type' => WorkCenterType::class,
            'is_active' => 'boolean',
            'capacity_per_hour' => 'decimal:4',
        ];
    }
}
