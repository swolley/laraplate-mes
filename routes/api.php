<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\SelfAuthenticatedApiRoute;
use Modules\MES\Http\Controllers\MachineDataIngestController;
use Modules\MES\Http\Middleware\AuthenticateMachineSource;

/*
 * Inbound endpoint of the machine sources (our edge agent or a customer gateway). The bearer
 * token identifies the source; the URL carries no source id. Temporarily self-authenticated: the bearer is a
 * machine source's token, not a user's, until the sources move to service accounts.
 */
Route::post('mes/machine-data', MachineDataIngestController::class)
    ->middleware([SelfAuthenticatedApiRoute::class, AuthenticateMachineSource::class, 'throttle:mes-machine-ingest'])
    ->name('machine-data.ingest');
