<?php

declare(strict_types=1);

namespace Modules\MES\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Validation\Rule;
use Modules\Core\Overrides\Model;
use Modules\ERP\Concerns\BelongsToCompany;
use Modules\ERP\Enums\ERPTables;
use Modules\MES\Database\Factories\MachineProfileFactory;
use Modules\MES\Enums\MESTables;
use Override;

/**
 * @property int $id
 * @property int|null $company_id
 * @property string $vendor
 * @property string $model
 * @property string $version
 * @property array<string, mixed> $definition
 */
final class MachineProfile extends Model
{
    use BelongsToCompany;

    /**
     * @var string
     */
    #[Override]
    protected $table = 'mes_machine_profiles';

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'company_id',
        'vendor',
        'model',
        'version',
        'definition',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();
        $unique = $this->company_id === null ? [] : [
            Rule::unique(MESTables::MachineProfiles->value, 'version')
                ->where('company_id', $this->company_id)
                ->where('vendor', $this->vendor)
                ->where('model', $this->model)
                ->ignore($this->getKey()),
        ];

        $rules['create'] = array_merge($rules['create'], [
            'company_id' => ['required', 'integer', 'exists:' . ERPTables::Companies->value . ',id'],
            'vendor' => ['required', 'string', 'max:128'],
            'model' => ['required', 'string', 'max:128'],
            'version' => ['required', 'string', 'max:32', ...$unique],
            'definition' => ['required', 'array'],
        ]);

        $rules['update'] = array_merge($rules['update'], [
            'vendor' => ['sometimes', 'string', 'max:128'],
            'model' => ['sometimes', 'string', 'max:128'],
            'version' => ['sometimes', 'string', 'max:32', ...$unique],
            'definition' => ['sometimes', 'array'],
        ]);

        return $rules;
    }

    /**
     * @return Factory<MachineProfile>
     */
    protected static function newFactory(): Factory
    {
        return MachineProfileFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'definition' => 'array',
        ];
    }
}
