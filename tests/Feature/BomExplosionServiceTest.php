<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MES\Enums\ConsumptionMethod;
use Modules\MES\Models\Bom;
use Modules\MES\Models\BomLine;
use Modules\MES\Services\BomExplosionService;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

it('explodes a multi-level bom into leaf component quantities', function (): void {
    $company = MesTestHelpers::makeCompany();
    $finished = MesTestHelpers::makeItem($company->id);
    $semi = MesTestHelpers::makeItem($company->id);
    $raw = MesTestHelpers::makeItem($company->id);

    // Parent BOM: 1 finished = 2 semi.
    $parent = Bom::factory()->create([
        'company_id' => $company->id,
        'item_id' => $finished->id,
        'valid_from' => now()->subDay()->toDateString(),
    ]);
    BomLine::query()->create([
        'bom_id' => $parent->id,
        'item_id' => $semi->id,
        'quantity' => 2,
        'uom' => 'pcs',
        'consumption_method' => ConsumptionMethod::Backflush->value,
        'sort_order' => 0,
    ]);

    // Child BOM: 1 semi = 3 raw.
    $child = Bom::factory()->create([
        'company_id' => $company->id,
        'item_id' => $semi->id,
        'valid_from' => now()->subDay()->toDateString(),
    ]);
    BomLine::query()->create([
        'bom_id' => $child->id,
        'item_id' => $raw->id,
        'quantity' => 3,
        'uom' => 'pcs',
        'consumption_method' => ConsumptionMethod::Backflush->value,
        'sort_order' => 0,
    ]);

    $lines = resolve(BomExplosionService::class)->explode($finished->id, 10, now());

    $raw_line = collect($lines)->firstWhere('item_id', $raw->id);

    expect($raw_line)->not->toBeNull()
        ->and($raw_line['quantity'])->toEqual(60.0) // 10 * 2 * 3
        ->and($raw_line['consumption_method'])->toBe('backflush');
});

it('returns an empty explosion when the item has no active bom', function (): void {
    $company = MesTestHelpers::makeCompany();
    $item = MesTestHelpers::makeItem($company->id);

    expect(resolve(BomExplosionService::class)->explode($item->id, 5, now()))->toBe([]);
});

it('emits a single-level line as a leaf requirement scaled by quantity', function (): void {
    $company = MesTestHelpers::makeCompany();
    $finished = MesTestHelpers::makeItem($company->id);
    $raw = MesTestHelpers::makeItem($company->id);

    $bom = Bom::factory()->create([
        'company_id' => $company->id,
        'item_id' => $finished->id,
        'valid_from' => now()->subDay()->toDateString(),
    ]);
    BomLine::query()->create([
        'bom_id' => $bom->id,
        'item_id' => $raw->id,
        'quantity' => 4,
        'uom' => 'kg',
        'consumption_method' => ConsumptionMethod::Manual->value,
        'sort_order' => 0,
    ]);

    $lines = resolve(BomExplosionService::class)->explode($finished->id, 5, now());

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['item_id'])->toBe($raw->id)
        ->and($lines[0]['quantity'])->toEqual(20.0)
        ->and($lines[0]['uom'])->toBe('kg')
        ->and($lines[0]['consumption_method'])->toBe('manual')
        ->and($lines[0]['level'])->toBe(0);
});

it('resolves the most recently effective bom version', function (): void {
    $company = MesTestHelpers::makeCompany();
    $item = MesTestHelpers::makeItem($company->id);

    $old = Bom::factory()->create([
        'company_id' => $company->id,
        'item_id' => $item->id,
        'version' => 'v1',
        'valid_from' => now()->subYear()->toDateString(),
        'valid_to' => now()->subMonth()->toDateString(),
    ]);
    $new = Bom::factory()->create([
        'company_id' => $company->id,
        'item_id' => $item->id,
        'version' => 'v2',
        'valid_from' => now()->subMonth()->toDateString(),
        'valid_to' => null,
    ]);

    $resolved = resolve(BomExplosionService::class)->getActiveBom($item->id, now());

    expect($resolved?->id)->toBe($new->id)
        ->and($resolved?->id)->not->toBe($old->id);
});
