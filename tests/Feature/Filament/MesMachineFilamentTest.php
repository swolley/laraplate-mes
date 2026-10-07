<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\MES\Filament\Resources\MachineDevices\Pages\EditMachineDevice;
use Modules\MES\Filament\Resources\MachineDevices\Pages\ListMachineDevices;
use Modules\MES\Filament\Resources\MachineDevices\RelationManagers\SignalsRelationManager;
use Modules\MES\Filament\Resources\MachineIncidents\Pages\ListMachineIncidents;
use Modules\MES\Filament\Resources\MachineMessages\Pages\ListMachineMessages;
use Modules\MES\Filament\Resources\MachineProfiles\Pages\EditMachineProfile;
use Modules\MES\Filament\Resources\MachineProfiles\Pages\ListMachineProfiles;
use Modules\MES\Filament\Resources\MachineSources\Pages\CreateMachineSource;
use Modules\MES\Filament\Resources\MachineSources\Pages\EditMachineSource;
use Modules\MES\Filament\Resources\MachineSources\Pages\ListMachineSources;
use Modules\MES\Filament\Resources\UnmappedSignals\Pages\ListUnmappedSignals;
use Modules\MES\Machine\MachineProfileService;
use Modules\MES\Enums\MachineIncidentType;
use Modules\MES\Enums\MachineMessageStatus;
use Modules\MES\Models\MachineDevice;
use Modules\MES\Models\MachineIncident;
use Modules\MES\Models\MachineMessage;
use Modules\MES\Models\MachineProfile;
use Modules\MES\Models\MachineSignal;
use Modules\MES\Models\MachineSource;
use Modules\MES\Models\UnmappedSignal;
use Modules\MES\Tests\Support\MesTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    if (! class_exists(App\Models\User::class)) {
        class_alias(User::class, App\Models\User::class);
    }

    $company = MesTestHelpers::makeCompany();
    $this->company = $company;
    /** @var App\Models\User $superadmin */
    $superadmin = App\Models\User::query()->create(User::factory()->raw());
    $superadmin->assignRole(Role::findOrCreate(config('permission.roles.superadmin'), 'web'));

    $this->actingAs($superadmin);
    Filament::setCurrentPanel('admin');
});

it('renders the machine configuration list pages with their records', function (string $page, Closure $make): void {
    $record = $make();

    $list = Livewire::test($page)->assertOk();

    expect($list->instance()->getTableRecords()->modelKeys())->toContain($record->getKey());
})->with([
    'sources' => [ListMachineSources::class, fn (): MachineSource => MachineSource::factory()->create()],
    'devices' => [ListMachineDevices::class, fn (): MachineDevice => MachineDevice::factory()->create()],
    'profiles' => [ListMachineProfiles::class, fn (): MachineProfile => MachineProfile::factory()->create()],
]);

it('creates a source from its form', function (): void {
    Livewire::test(CreateMachineSource::class)
        ->fillForm(['company_id' => $this->company->id, 'code' => 'gw-9', 'name' => 'Gateway 9', 'normalizer' => 'canonical', 'transport' => 'http', 'heartbeat_timeout_seconds' => 120, 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(MachineSource::query()->where('code', 'gw-9')->exists())->toBeTrue();
});

it('issues a token once and revokes it', function (): void {
    $source = MachineSource::factory()->create();

    $page = Livewire::test(EditMachineSource::class, ['record' => $source->getKey()])->callAction('issue_token');
    // Read the notification before assertNotified() claims it.
    $plain = collect(session('filament.claimed_notifications', []))->pluck('body')->filter()->implode(' ');
    $page->assertNotified();
    $stored = DB::table('personal_access_tokens')->value('token');

    expect($source->tokens()->count())->toBe(1)
        ->and($plain)->toMatch('/\d+\|[A-Za-z0-9]{20,}/')
        ->and($plain)->not->toContain((string) $stored);

    Livewire::test(EditMachineSource::class, ['record' => $source->getKey()])
        ->callAction('revoke_token')
        ->assertNotified();

    expect($source->tokens()->count())->toBe(0);
});

it('hides the token actions from a user without the permission', function (): void {
    $source = MachineSource::factory()->create();
    $this->actingAs(User::factory()->create());

    Livewire::test(EditMachineSource::class, ['record' => $source->getKey()])
        ->assertActionHidden('issue_token')
        ->assertActionHidden('revoke_token')
        ->assertActionHidden('reprocess_range');
});

it('reprocesses a range of messages from the source page', function (): void {
    Illuminate\Support\Facades\Queue::fake();
    $source = MachineSource::factory()->create();
    MachineMessage::factory()->processed()->create(['source_id' => $source->id, 'received_at' => '2026-10-05 10:00:00']);

    Livewire::test(EditMachineSource::class, ['record' => $source->getKey()])
        ->callAction('reprocess_range', ['from' => '2026-10-05 00:00:00', 'to' => '2026-10-05 23:59:59'])
        ->assertNotified();

    Illuminate\Support\Facades\Queue::assertPushed(Modules\MES\Jobs\ProcessMachineMessageJob::class, 1);
});

it('applies a profile to a device from its page', function (): void {
    $profile = MachineProfile::factory()->create(['definition' => ['signals' => [['key' => 'parts', 'role' => 'good_count', 'data_type' => 'number', 'config' => ['mode' => 'delta']]]]]);
    $device = MachineDevice::factory()->create();

    Livewire::test(EditMachineDevice::class, ['record' => $device->getKey()])
        ->callAction('apply_profile', ['profile_id' => $profile->id])
        ->assertNotified();

    expect($device->signals()->pluck('key')->all())->toBe(['parts']);
});

it('imports an exported profile and refuses an invalid file', function (): void {
    $json = resolve(MachineProfileService::class)->export(MachineProfile::factory()->create(['vendor' => 'Acme', 'model' => 'X', 'version' => '1', 'definition' => ['signals' => []]]));
    MachineProfile::query()->forceDelete();

    Livewire::test(ListMachineProfiles::class)
        ->callAction('import', ['company_id' => $this->company->id, 'file' => UploadedFile::fake()->createWithContent('profile.json', $json)])
        ->assertNotified();
    expect(MachineProfile::query()->where('vendor', 'Acme')->exists())->toBeTrue();

    Livewire::test(ListMachineProfiles::class)
        ->callAction('import', ['company_id' => $this->company->id, 'file' => UploadedFile::fake()->createWithContent('bad.json', '{broken')])
        ->assertNotified();
    expect(MachineProfile::query()->count())->toBe(1);
});

it('exports a profile as a download', function (): void {
    $profile = MachineProfile::factory()->create(['vendor' => 'Acme', 'model' => 'X', 'version' => '1']);

    Livewire::test(EditMachineProfile::class, ['record' => $profile->getKey()])
        ->callAction('export')
        ->assertFileDownloaded('acme-x-1.json');
});

it('lists the signals of a device in its relation manager and refuses a broken config', function (): void {
    $signal = MachineSignal::factory()->create();
    $device = $signal->device;

    $manager = Livewire::test(SignalsRelationManager::class, ['ownerRecord' => $device, 'pageClass' => EditMachineDevice::class])->assertOk();
    expect($manager->instance()->getTableRecords()->modelKeys())->toBe([$signal->getKey()]);

    $manager->callAction(\Filament\Actions\Testing\TestAction::make('create')->table(), ['key' => 'bad', 'role' => 'good_count', 'data_type' => 'number', 'config' => '{"mode":"sometimes"}'])
        ->assertHasFormErrors();
    expect($device->signals()->count())->toBe(1);
});

it('renders the unmapped signals, inbox and incident lists', function (string $page, Closure $make): void {
    $record = $make();

    $list = Livewire::test($page)->assertOk();

    expect($list->instance()->getTableRecords()->modelKeys())->toContain($record->getKey());
})->with([
    'unmapped signals' => [ListUnmappedSignals::class, fn (): UnmappedSignal => UnmappedSignal::factory()->create()],
    'inbox' => [ListMachineMessages::class, fn (): MachineMessage => MachineMessage::factory()->create()],
    'incidents' => [ListMachineIncidents::class, fn (): MachineIncident => MachineIncident::factory()->create()],
]);

it('maps an unmapped signal from its row action, and the row disappears', function (): void {
    $device = MachineDevice::factory()->create(['external_id' => 'press-07']);
    $unmapped = UnmappedSignal::factory()->create(['source_id' => $device->source_id, 'device_external_id' => 'press-07', 'signal_key' => 'spindle']);

    Livewire::test(ListUnmappedSignals::class)
        ->callTableAction('map', $unmapped, ['role' => 'process_value', 'data_type' => 'number', 'unit' => 'rpm'])
        ->assertNotified();

    expect(UnmappedSignal::query()->count())->toBe(0)
        ->and($device->signals()->where('key', 'spindle')->exists())->toBeTrue();
});

it('offers no map action on a raw state value row', function (): void {
    $unmapped = UnmappedSignal::factory()->create(['signal_key' => 'state#HOLDING']);

    Livewire::test(ListUnmappedSignals::class)->assertTableActionHidden('map', $unmapped);
});

it('reprocesses a failed message from the inbox', function (): void {
    Illuminate\Support\Facades\Queue::fake();
    $message = MachineMessage::factory()->failed()->create();

    Livewire::test(ListMachineMessages::class)
        ->callTableAction('reprocess', $message)
        ->assertNotified();

    expect($message->fresh()->status)->toBe(MachineMessageStatus::Pending);
});

it('filters the incidents by type', function (): void {
    $gap = MachineIncident::factory()->create(['type' => MachineIncidentType::SeqGap->value]);
    MachineIncident::factory()->create(['type' => MachineIncidentType::ClockSkew->value]);

    $list = Livewire::test(ListMachineIncidents::class)->filterTable('type', MachineIncidentType::SeqGap->value);

    expect($list->instance()->getTableRecords()->modelKeys())->toBe([$gap->getKey()]);
});

it('lists the unattributed measurements and assigns one to a quality check from its row action', function (): void {
    $row = Modules\MES\Models\UnattributedMeasurement::factory()->create(['value' => 10.5]);
    $characteristic = $row->signal->characteristic;
    $check = Modules\MES\Models\QualityCheck::factory()->create(['company_id' => $row->company_id, 'quality_plan_id' => $characteristic->quality_plan_id]);

    $list = Livewire::test(Modules\MES\Filament\Resources\UnattributedMeasurements\Pages\ListUnattributedMeasurements::class)->assertOk();
    expect($list->instance()->getTableRecords()->modelKeys())->toContain($row->getKey());

    $list->callTableAction('assign', $row, ['quality_check_id' => $check->id])->assertNotified();

    expect($row->fresh()->assigned_at)->not->toBeNull()
        ->and(Modules\MES\Models\QualityCheckMeasurement::query()->where('quality_check_id', $check->id)->count())->toBe(1)
        ->and($check->fresh()->status)->toBe(Modules\MES\Enums\QualityCheckStatus::Passed);
});

it('hides the assign action from an assigned row and from a user who may not insert machine signals', function (): void {
    $assigned = Modules\MES\Models\UnattributedMeasurement::factory()->create(['assigned_at' => now()]);
    $waiting = Modules\MES\Models\UnattributedMeasurement::factory()->create();

    Livewire::test(Modules\MES\Filament\Resources\UnattributedMeasurements\Pages\ListUnattributedMeasurements::class)
        ->assertTableActionHidden('assign', $assigned)
        ->assertTableActionVisible('assign', $waiting);

    $this->actingAs(user_class()::factory()->create());

    Livewire::test(Modules\MES\Filament\Resources\UnattributedMeasurements\Pages\ListUnattributedMeasurements::class)
        ->assertTableActionHidden('assign', $waiting);
});
