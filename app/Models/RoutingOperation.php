<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Concerns\HasPrefixedTableName;
use Modules\Core\Models\Concerns\HasValidations;
use Modules\Core\Models\Concerns\HasVersions;
use Modules\MES\Database\Factories\RoutingOperationFactory;
use Modules\MES\Enums\MESTables;
use Override;

final class RoutingOperation extends Model
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
    protected $table = 'mes_routing_operations';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'routing_id',
        'work_center_id',
        'sequence',
        'description',
        'setup_time_minutes',
        'cycle_time_minutes',
        'is_parallel',
    ];

    /**
     * Validation rules for create and update operations. The unique `(routing_id, sequence)` pair is
     * left to the database constraint.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getRules(): array
    {
        $rules = $this->getRulesFromTrait();

        $rules['create'] = array_merge($rules['create'], [
            'routing_id' => ['required', 'integer', 'exists:' . MESTables::Routings->value . ',id'],
            'work_center_id' => ['required', 'integer', 'exists:' . MESTables::WorkCenters->value . ',id'],
            'sequence' => ['required', 'integer'],
            'description' => ['required', 'string', 'max:255'],
            'setup_time_minutes' => ['sometimes', 'integer', 'min:0'],
            'cycle_time_minutes' => ['sometimes', 'numeric', 'min:0'],
            'is_parallel' => ['sometimes', 'boolean'],
        ]);

        $rules['update'] = array_merge($rules['update'], [
            'routing_id' => ['sometimes', 'integer', 'exists:' . MESTables::Routings->value . ',id'],
            'work_center_id' => ['sometimes', 'integer', 'exists:' . MESTables::WorkCenters->value . ',id'],
            'sequence' => ['sometimes', 'integer'],
            'description' => ['sometimes', 'string', 'max:255'],
            'setup_time_minutes' => ['sometimes', 'integer', 'min:0'],
            'cycle_time_minutes' => ['sometimes', 'numeric', 'min:0'],
            'is_parallel' => ['sometimes', 'boolean'],
        ]);

        return $rules;
    }

    /**
     * The routing this operation belongs to.
     *
     * @return BelongsTo<Routing, $this>
     */
    public function routing(): BelongsTo
    {
        return $this->belongsTo(Routing::class);
    }

    /**
     * The work center this operation runs on.
     *
     * @return BelongsTo<WorkCenter, $this>
     */
    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory<RoutingOperation>
     */
    protected static function newFactory(): Factory
    {
        return RoutingOperationFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'sequence' => 'int',
            'setup_time_minutes' => 'int',
            'cycle_time_minutes' => 'decimal:4',
            'is_parallel' => 'boolean',
        ];
    }
}
