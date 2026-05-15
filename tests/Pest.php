<?php

declare(strict_types=1);

use Modules\MES\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->in(__DIR__ . '/Feature', __DIR__ . '/Unit');
