<?php

declare(strict_types=1);

namespace Modules\MES\Observers;

use Modules\MES\Machine\SignalResolver;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineSignal;

/**
 * Forgets the cached signal map of a source whenever its devices or their signals change.
 */
final class MachineConfigurationObserver
{
    public function __construct(
        private readonly SignalResolver $resolver,
    ) {}

    public function saved(MachineDevice|MachineSignal $model): void
    {
        $this->forget($model);
    }

    public function deleted(MachineDevice|MachineSignal $model): void
    {
        $this->forget($model);
    }

    public function restored(MachineDevice|MachineSignal $model): void
    {
        $this->forget($model);
    }

    private function forget(MachineDevice|MachineSignal $model): void
    {
        $source_id = $model instanceof MachineDevice
            ? $model->source_id
            : MachineDevice::query()->withoutGlobalScopes()->whereKey($model->device_id)->value('source_id');

        if (is_numeric($source_id)) {
            $this->resolver->forget((int) $source_id);
        }
    }
}
