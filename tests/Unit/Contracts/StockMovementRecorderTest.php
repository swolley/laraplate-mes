<?php

declare(strict_types=1);

use Modules\MES\Contracts\StockMovementRecorder;
use Modules\MES\Data\StockMovementData;

it('exists as an interface', function (): void {
    expect(interface_exists(StockMovementRecorder::class))->toBeTrue();
});

it('declares the record method', function (): void {
    $reflection = new ReflectionClass(StockMovementRecorder::class);

    expect($reflection->isInterface())->toBeTrue();
    expect($reflection->hasMethod('record'))->toBeTrue();
});

it('record method accepts StockMovementData and returns void', function (): void {
    $reflection = new ReflectionClass(StockMovementRecorder::class);
    $method = $reflection->getMethod('record');

    $parameters = $method->getParameters();
    expect($parameters)->toHaveCount(1);

    $param = $parameters[0];
    expect($param->getName())->toBe('data');

    $type = $param->getType();
    expect($type)->toBeInstanceOf(ReflectionNamedType::class);
    expect($type->getName())->toBe(StockMovementData::class);

    $returnType = $method->getReturnType();
    expect($returnType)->toBeInstanceOf(ReflectionNamedType::class);
    expect($returnType->getName())->toBe('void');
});
