<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Support\CrudApiExposure;
use Modules\ERP\Models\Company;
use Modules\MES\Enums\WorkCenterType;
use Modules\MES\Models\WorkCenter;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function workCenterAttributes(Company $company, string $code, string $name = 'Work center'): array
{
    return [
        'company_id' => $company->id,
        'code' => $code,
        'name' => $name,
        'type' => WorkCenterType::Machine->value,
        'capacity_per_hour' => 10,
        'capacity_uom' => 'pcs',
    ];
}

it('rejects a duplicate work center code within the same company', function (): void {
    $company = MesTestHelpers::makeCompany();
    WorkCenter::withoutGlobalScopes()->create(workCenterAttributes($company, 'WC-DUP', 'First'));

    expect(fn () => WorkCenter::withoutGlobalScopes()->create(workCenterAttributes($company, 'WC-DUP', 'Second')))
        ->toThrow(ValidationException::class);

    expect(WorkCenter::withoutGlobalScopes()->where('code', 'WC-DUP')->count())->toBe(1);
});

it('accepts the same work center code in another company', function (): void {
    WorkCenter::withoutGlobalScopes()->create(workCenterAttributes(MesTestHelpers::makeCompany(), 'WC-SHARED'));
    WorkCenter::withoutGlobalScopes()->create(workCenterAttributes(MesTestHelpers::makeCompany(), 'WC-SHARED'));

    expect(WorkCenter::withoutGlobalScopes()->where('code', 'WC-SHARED')->count())->toBe(2);
});

it('lets a work center keep its own code on update but not take a sibling code', function (): void {
    $company = MesTestHelpers::makeCompany();
    $first = WorkCenter::withoutGlobalScopes()->create(workCenterAttributes($company, 'WC-ONE'));
    $second = WorkCenter::withoutGlobalScopes()->create(workCenterAttributes($company, 'WC-TWO'));

    $first->update(['code' => 'WC-ONE', 'name' => 'Renamed']);

    expect($first->fresh()->name)->toBe('Renamed')
        ->and(fn () => $second->update(['code' => 'WC-ONE']))->toThrow(ValidationException::class);
});

it('deactivates a work center', function (): void {
    $wc = WorkCenter::withoutGlobalScopes()->create([
        ...workCenterAttributes(MesTestHelpers::makeCompany(), 'WC-OFF'),
        'is_active' => true,
    ]);

    $wc->deactivate();

    expect($wc->fresh()->is_active)->toBeFalse()
        ->and(WorkCenter::withoutGlobalScopes()->active()->whereKey($wc->id)->exists())->toBeFalse();
});

it('answers 422 on a duplicate code inserted through the generic CRUD', function (): void {
    CrudApiExposure::enable();
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate(config('permission.roles.superadmin'), 'web'));
    $this->actingAs($user);

    $company = MesTestHelpers::makeCompany();
    WorkCenter::withoutGlobalScopes()->create(workCenterAttributes($company, 'WC-API'));

    $this->postJson(
        route('core.crud.insert', ['module' => 'mes', 'entity' => 'work_centers']),
        workCenterAttributes($company, 'WC-API', 'Duplicate'),
    )->assertUnprocessable();

    expect(WorkCenter::withoutGlobalScopes()->where('code', 'WC-API')->count())->toBe(1);
});
