<?php

declare(strict_types=1);

namespace Modules\MES\Database\Factories\Concerns;

use Illuminate\Support\Str;
use Modules\ERP\Models\Company;

trait UsesDefaultCompany
{
    /**
     * The first company, created when none exists yet.
     */
    protected function defaultCompanyId(): int
    {
        return Company::query()->withoutGlobalScopes()->first()?->id
            ?? Company::query()->withoutGlobalScopes()->create([
                'slug' => Str::limit(fake()->unique()->slug(), 64, ''),
                'name' => fake()->company(),
                'fiscal_country' => 'IT',
                'default_currency' => 'EUR',
            ])->id;
    }
}
