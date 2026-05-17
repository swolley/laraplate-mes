<?php

declare(strict_types=1);

/**
 * StockMovementData tests.
 *
 * Do not assert immutability in-process: tests/Pest.php enables DG\BypassFinals,
 * which disables readonly enforcement so Mockery can replace methods on final classes.
 */

use Modules\MES\Data\StockMovementData;
use Symfony\Component\Process\Process;

describe('StockMovementData', function (): void {
    it('constructs correctly with all fields', function (): void {
        $occurredAt = new DateTimeImmutable('2025-01-15 10:30:00');

        $dto = new StockMovementData(
            item_id: 42,
            warehouse_id: 7,
            company_id: 1,
            direction: 'out',
            quantity: 100,
            source_type: 'mes_production_orders',
            source_id: 99,
            occurred_at: $occurredAt,
        );

        expect($dto->item_id)->toBe(42)
            ->and($dto->warehouse_id)->toBe(7)
            ->and($dto->company_id)->toBe(1)
            ->and($dto->direction)->toBe('out')
            ->and($dto->quantity)->toBe(100)
            ->and($dto->source_type)->toBe('mes_production_orders')
            ->and($dto->source_id)->toBe(99)
            ->and($dto->occurred_at)->toBe($occurredAt);
    });

    it('accepts direction "in"', function (): void {
        $dto = new StockMovementData(
            item_id: 1,
            warehouse_id: 1,
            company_id: 1,
            direction: 'in',
            quantity: 50,
            source_type: 'mes_production_orders',
            source_id: 1,
            occurred_at: new DateTimeImmutable(),
        );

        expect($dto->direction)->toBe('in');
    });

    it('accepts any DateTimeInterface implementation', function (): void {
        $dateTime = new DateTimeImmutable('2025-06-01 08:00:00');

        $dto = new StockMovementData(
            item_id: 1,
            warehouse_id: 1,
            company_id: 1,
            direction: 'out',
            quantity: 10,
            source_type: 'mes_production_orders',
            source_id: 1,
            occurred_at: $dateTime,
        );

        expect($dto->occurred_at)->toBeInstanceOf(DateTimeInterface::class)
            ->and($dto->occurred_at)->toBe($dateTime);
    });

    it('is immutable — properties cannot be reassigned at runtime', function (): void {
        $autoload = dirname(__DIR__, 5) . '/vendor/autoload.php';

        $process = new Process([
            PHP_BINARY,
            '-r',
            sprintf(
                <<<'PHP'
                require %s;
                $dto = new \Modules\MES\Data\StockMovementData(
                    item_id: 1,
                    warehouse_id: 1,
                    company_id: 1,
                    direction: 'in',
                    quantity: 1,
                    source_type: 'mes_production_orders',
                    source_id: 1,
                    occurred_at: new \DateTimeImmutable(),
                );
                try {
                    $dto->item_id = 999;
                    exit(1);
                } catch (\Error) {
                    exit(0);
                }
                PHP,
                var_export($autoload, true),
            ),
        ]);

        $process->run();

        expect($process->isSuccessful())->toBeTrue();
    });
});
