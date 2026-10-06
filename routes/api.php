<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\MES\Http\Controllers\MachineDataIngestController;
use Modules\MES\Http\Middleware\AuthenticateMachineSource;

/*
 * Inbound endpoint of the machine sources (our edge agent or a customer gateway). The bearer
 * token identifies the source; the URL carries no source id.
 */
Route::post('mes/machine-data', MachineDataIngestController::class)
    ->middleware([AuthenticateMachineSource::class, 'throttle:mes-machine-ingest'])
    ->name('machine-data.ingest');
