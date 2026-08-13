<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Overrides\Model;
use Modules\ERP\Concerns\BelongsToCompany;
use Modules\ERP\Enums\ERPTables;
use Modules\ERP\Models\Item;
use Modules\MES\Database\Factories\BomFactory;
use Override;

/**
 * @mixin \Eloquent
 *
 * @property int $id
 * @property int $company_id
 * @property int $item_id
 * @property string $version
 * @property \Illuminate\Support\Carbon $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_to
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @mixin IdeHelperBom
 */
final class Bom extends Model
{
    use BelongsToCompany;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_boms';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'item_id',
        'version',
        'valid_from',
        'valid_to',
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

        $rules['create'] = array_merge($rules['create'], [
            'company_id' => ['required', 'integer', 'exists:' . ERPTables::Companies->value . ',id'],
            'item_id' => ['required', 'integer', 'exists:' . ERPTables::Items->value . ',id'],
            'version' => ['required', 'string', 'max:32'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $rules['update'] = array_merge($rules['update'], [
            'version' => ['sometimes', 'string', 'max:32'],
            'valid_from' => ['sometimes', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return $rules;
    }

    /**
     * The finished/semi-finished item this bill of materials produces.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Component lines of this bill of materials.
     *
     * @return HasMany<BomLine, $this>
     */
    public function bomLines(): HasMany
    {
        return $this->hasMany(BomLine::class)->orderBy('sort_order');
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory<Bom>
     */
    protected static function newFactory(): Factory
    {
        return BomFactory::new();
    }

    /**
     * Scope to filter only active bills of materials.
     *
     * @param  Builder<Bom>  $query
     * @return Builder<Bom>
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
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
