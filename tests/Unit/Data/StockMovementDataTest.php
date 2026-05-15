<?php

declare(strict_types=1);

use Modules\MES\Data\StockMovementData;

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
        $dateTime = new DateTime('2025-06-01 08:00:00');

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

    it('is immutable — properties cannot be reassigned', function (): void {
        $dto = new StockMovementData(
            item_id: 1,
            warehouse_id: 1,
            company_id: 1,
            direction: 'in',
            quantity: 5,
            source_type: 'mes_production_orders',
            source_id: 1,
            occurred_at: new DateTimeImmutable(),
        );

        expect(fn () => $dto->item_id = 999) // @phpstan-ignore-line
            ->toThrow(Error::class);
    });
});
