<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\MES\Models\BomLine;
use Modules\MES\Models\RoutingOperation;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

// The line factory builds an item under the first company, so one has to exist.
beforeEach(fn () => MesTestHelpers::makeCompany());

it('rejects a bom line that breaks its rules on create', function (array $invalid): void {
    expect(fn () => BomLine::factory()->create($invalid))->toThrow(ValidationException::class);
})->with([
    'zero quantity' => [['quantity' => 0]],
    'negative quantity' => [['quantity' => -1]],
    'non-numeric quantity' => [['quantity' => 'abc']],
    'unit of measure too long' => [['uom' => str_repeat('x', 17)]],
    'non-integer sort order' => [['sort_order' => 'first']],
]);

it('refuses an unknown consumption method before validation, through the enum cast', function (): void {
    expect(fn () => BomLine::factory()->create(['consumption_method' => 'sometimes']))->toThrow(ValueError::class);
});

it('rejects an edit that breaks the rules of a bom line', function (): void {
    $line = BomLine::factory()->create(['quantity' => 2]);

    expect(fn () => $line->update(['quantity' => -3]))->toThrow(ValidationException::class);
    expect($line->fresh()->quantity)->toEqual(2);
});

it('accepts a valid bom line', function (): void {
    expect(BomLine::factory()->create(['quantity' => 2.5, 'uom' => 'kg'])->exists)->toBeTrue();
});

it('rejects a routing operation that breaks its rules on create', function (array $invalid): void {
    expect(fn () => RoutingOperation::factory()->create($invalid))->toThrow(ValidationException::class);
})->with([
    'non-integer sequence' => [['sequence' => 'first']],
    'empty description' => [['description' => '']],
    'description too long' => [['description' => str_repeat('x', 256)]],
    'negative setup time' => [['setup_time_minutes' => -1]],
    'negative cycle time' => [['cycle_time_minutes' => -0.5]],
    'non-boolean parallel flag' => [['is_parallel' => 'maybe']],
]);

it('rejects an edit that breaks the rules of a routing operation', function (): void {
    $operation = RoutingOperation::factory()->create(['setup_time_minutes' => 5]);

    expect(fn () => $operation->update(['setup_time_minutes' => -1]))->toThrow(ValidationException::class);
});

it('accepts a valid routing operation', function (): void {
    expect(RoutingOperation::factory()->create(['description' => 'Cut', 'cycle_time_minutes' => 1.5])->exists)->toBeTrue();
});
