<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MES\Machine\Support\IdempotentWriter;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineSource;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

it('stores a row once and reports a repeat as already stored', function (): void {
    MesTestHelpers::makeCompany();
    $source = MachineSource::factory()->create();
    $writer = new IdempotentWriter();
    $row = [
        'company_id' => $source->company_id,
        'source_id' => $source->id,
        'message_id' => 'm-1',
        'transport' => 'http',
        'payload' => '{}',
        'received_at' => now(),
        'status' => 'pending',
        'attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    expect($writer->insert(new MachineMessage()->getConnection(), 'mes_machine_messages', $row))->toBeTrue()
        ->and($writer->insert(new MachineMessage()->getConnection(), 'mes_machine_messages', $row))->toBeFalse()
        ->and(MachineMessage::query()->count())->toBe(1);
});

it('treats a unique violation as already stored on a driver without insert-or-ignore', function (): void {
    $builder = Mockery::mock(Builder::class);
    $builder->shouldReceive('insert')->once()->andThrow(new UniqueConstraintViolationException('oracle', 'insert', [], new Exception('ORA-00001')));
    $builder->shouldNotReceive('insertOrIgnore');
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('oracle');
    $connection->shouldReceive('table')->with('t')->andReturn($builder);

    expect(new IdempotentWriter()->insert($connection, 't', ['a' => 1]))->toBeFalse();
});

it('lets other errors through on a driver without insert-or-ignore', function (): void {
    $builder = Mockery::mock(Builder::class);
    $builder->shouldReceive('insert')->once()->andThrow(new RuntimeException('disk full'));
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('oracle');
    $connection->shouldReceive('table')->with('t')->andReturn($builder);

    expect(fn () => new IdempotentWriter()->insert($connection, 't', ['a' => 1]))->toThrow(RuntimeException::class, 'disk full');
});

it('does not use insert-or-ignore on SQL Server, whose grammar lacks it', function (): void {
    $builder = Mockery::mock(Builder::class);
    $builder->shouldReceive('insert')->once()->andThrow(new UniqueConstraintViolationException('sqlsrv', 'insert', [], new Exception('2627')));
    $builder->shouldNotReceive('insertOrIgnore');
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('sqlsrv');
    $connection->shouldReceive('table')->with('t')->andReturn($builder);

    expect(new IdempotentWriter()->insert($connection, 't', ['a' => 1]))->toBeFalse();
});

it('stores many rows in one go, skips the ones already stored and reports how many were new', function (): void {
    MesTestHelpers::makeCompany();
    $source = MachineSource::factory()->create();
    $writer = new IdempotentWriter();
    $row = static fn (string $id): array => [
        'company_id' => $source->company_id,
        'source_id' => $source->id,
        'message_id' => $id,
        'transport' => 'http',
        'payload' => '{}',
        'received_at' => now(),
        'status' => 'pending',
        'attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];
    $connection = new MachineMessage()->getConnection();

    expect($writer->insertMany($connection, 'mes_machine_messages', [$row('a'), $row('b')]))->toBe(2)
        ->and($writer->insertMany($connection, 'mes_machine_messages', [$row('b'), $row('c')]))->toBe(1)
        ->and($writer->insertMany($connection, 'mes_machine_messages', []))->toBe(0)
        ->and(MachineMessage::query()->count())->toBe(3);
});

it('stores many rows in one statement on a driver without insert-or-ignore when none is a duplicate', function (): void {
    $builder = Mockery::mock(Builder::class);
    $builder->shouldReceive('insert')->once()->with([['a' => 1], ['a' => 2]])->andReturn(true);
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('sqlsrv');
    $connection->shouldReceive('table')->with('t')->andReturn($builder);

    expect(new IdempotentWriter()->insertMany($connection, 't', [['a' => 1], ['a' => 2]]))->toBe(2);
});

it('falls back to one row at a time for a chunk with a duplicate on a driver without insert-or-ignore', function (): void {
    $builder = Mockery::mock(Builder::class);
    $builder->shouldReceive('insert')->with([['a' => 1], ['a' => 2]])->once()->andThrow(new UniqueConstraintViolationException('sqlsrv', 'insert', [], new Exception('duplicate')));
    $builder->shouldReceive('insert')->with([['a' => 1]])->once()->andReturn(true);
    $builder->shouldReceive('insert')->with([['a' => 2]])->once()->andThrow(new UniqueConstraintViolationException('sqlsrv', 'insert', [], new Exception('duplicate')));
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('sqlsrv');
    $connection->shouldReceive('table')->with('t')->andReturn($builder);

    expect(new IdempotentWriter()->insertMany($connection, 't', [['a' => 1], ['a' => 2]]))->toBe(1);
});
