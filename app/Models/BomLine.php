<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;
use Modules\Core\Models\Concerns\HasPrefixedTableName;
use Modules\Core\Models\Concerns\HasValidations;
use Modules\Core\Models\Concerns\HasVersions;
use Modules\ERP\Enums\ERPTables;
use Modules\ERP\Models\Item;
use Modules\MES\Database\Factories\BomLineFactory;
use Modules\MES\Enums\ConsumptionMethod;
use Modules\MES\Enums\MESTables;
use Override;

final class BomLine extends Model
{
    use HasFactory;
    use HasPrefixedTableName;
    use HasValidations {
        getRules as private getRulesFromTrait;
    }
    use HasVersions;

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_bom_lines';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'bom_id',
        'item_id',
        'quantity',
        'uom',
        'consumption_method',
        'routing_operation_id',
        'sort_order',
    ];

    /**
     * Validation rules for create and update operations.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getRules(): array
    {
        $rules = $this->getRulesFromTrait();

        $rules['create'] = array_merge($rules['create'], [
            'bom_id' => ['required', 'integer', 'exists:' . MESTables::Boms->value . ',id'],
            'item_id' => ['required', 'integer', 'exists:' . ERPTables::Items->value . ',id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'uom' => ['required', 'string', 'max:16'],
            'consumption_method' => ['required', Rule::enum(ConsumptionMethod::class)],
            'routing_operation_id' => ['nullable', 'integer', 'exists:' . MESTables::RoutingOperations->value . ',id'],
            'sort_order' => ['sometimes', 'integer'],
        ]);

        $rules['update'] = array_merge($rules['update'], [
            'bom_id' => ['sometimes', 'integer', 'exists:' . MESTables::Boms->value . ',id'],
            'item_id' => ['sometimes', 'integer', 'exists:' . ERPTables::Items->value . ',id'],
            'quantity' => ['sometimes', 'numeric', 'gt:0'],
            'uom' => ['sometimes', 'string', 'max:16'],
            'consumption_method' => ['sometimes', Rule::enum(ConsumptionMethod::class)],
            'routing_operation_id' => ['nullable', 'integer', 'exists:' . MESTables::RoutingOperations->value . ',id'],
            'sort_order' => ['sometimes', 'integer'],
        ]);

        return $rules;
    }

    /**
     * The bill of materials this line belongs to.
     *
     * @return BelongsTo<Bom, $this>
     */
    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    /**
     * The component item consumed by this line.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * The routing operation that backflushes this line, when set.
     *
     * @return BelongsTo<RoutingOperation, $this>
     */
    public function routingOperation(): BelongsTo
    {
        return $this->belongsTo(RoutingOperation::class);
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory<BomLine>
     */
    protected static function newFactory(): Factory
    {
        return BomLineFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'consumption_method' => ConsumptionMethod::class,
            'routing_operation_id' => 'int',
            'sort_order' => 'int',
        ];
    }
}
