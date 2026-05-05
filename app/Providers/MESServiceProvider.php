<?php

declare(strict_types=1);

namespace Modules\MES\Providers;

use Modules\Core\Overrides\ModuleServiceProvider;
use Override;

/**
 * @property \Illuminate\Foundation\Application $app
 */
final class MESServiceProvider extends ModuleServiceProvider
{
    #[Override]
    protected string $name = 'MES';

    #[Override]
    protected string $nameLower = 'mes';

    #[Override]
    public function register(): void
    {
        parent::register();
    }
}
