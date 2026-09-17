<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Contracts\IActivatableModel;
use Modules\Core\Models\Concerns\HasActivation;
use Modules\ERP\Models\Company;
use Modules\ERP\Models\Item;
use Modules\MES\Database\Factories\QualityPlanFactory;
use Override;

/**
 * Date-effective set of quality characteristics expected for an item, optionally
 * scoped to a routing operation (in-process control) or, when the operation is
 * null, to the finished item (final inspection). Drives the automatic creation
 * of {@see QualityCheck} records on operation/order completion.
 */
final class QualityPlan extends Model implements IActivatableModel
{
    use HasActivation, HasFactory;

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
