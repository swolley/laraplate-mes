<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * One correction of a declared quantity of an operation. Append-only: rows are written, never updated.
 *
 * @property int $id
 * @property int $operation_id
 * @property int|null $user_id
 * @property string $field
 * @property string|null $old_value
 * @property string|null $new_value
 * @property \Illuminate\Support\Carbon|null $created_at
 */
final class OperationQuantityAudit extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_operation_quantity_audits';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'operation_id',
        'user_id',
        'field',
        'old_value',
        'new_value',
    ];

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'old_value' => 'decimal:4',
            'new_value' => 'decimal:4',
        ];
    }
}
