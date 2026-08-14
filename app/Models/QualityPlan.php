<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ERP\Models\Company;
use Modules\ERP\Models\Item;
use Modules\MES\Database\Factories\QualityPlanFactory;
use Override;

/**
 * Date-effective set of quality characteristics expected for an item, optionally
 * scoped to a routing operation (in-process control) or, when the operation is
 * null, to the finished item (final inspection). Drives the automatic creation
 * of {@see QualityCheck} records on operation/order completion.
 *
 * @mixin \Eloquent
 *
 * @property int $id
 * @property int $company_id
 * @property int $item_id
 * @property int|null $routing_operation_id
 * @property string $name
 * @property string $version
 * @property \Illuminate\Support\Carbon $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_to
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @mixin IdeHelperQualityPlan
 */
final class QualityPlan extends Model
{
    use HasFactory;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_quality_plans';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'item_id',
        'routing_operation_id',
        'name',
        'version',
        'valid_from',
        'valid_to',
        'is_active',
    ];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<RoutingOperation, $this>
     */
    public function routingOperation(): BelongsTo
    {
        return $this->belongsTo(RoutingOperation::class);
    }

    /**
     * @return HasMany<QualityPlanCharacteristic, $this>
     */
    public function characteristics(): HasMany
    {
        return $this->hasMany(QualityPlanCharacteristic::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<QualityCheck, $this>
     */
    public function qualityChecks(): HasMany
    {
        return $this->hasMany(QualityCheck::class);
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory<QualityPlan>
     */
    protected static function newFactory(): Factory
    {
        return QualityPlanFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
