<?php

declare(strict_types=1);

namespace Modules\MES\Policies;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Support\PermissionName;
use Modules\MES\Enums\NonConformanceStatus;
use Modules\MES\Enums\ProductionOrderOperationStatus;
use Modules\MES\Models\Bom;
use Modules\MES\Models\Downtime;
use Modules\MES\Models\LotNumber;
use Modules\MES\Models\NonConformance;
use Modules\MES\Models\ProductionOrder;
use Modules\MES\Models\ProductionOrderOperation;
use Modules\MES\Models\QualityCheck;

/**
 * Authorizes MES domain actions on the internal `/app` surface.
 *
 * Each method pairs an intrinsic state guard with the seeded
 * `{connection}.{table}.{action}` permission, mirroring
 * {@see \Modules\ERP\Policies\ERPModelPolicy}. Generic CRUD verbs keep going
 * through the authorization service, not this policy.
 */
final class MesModelPolicy
{
    public function release(User $user, Model $record): bool
    {
        return $this->allowsDomainAction($user, $record, 'release', static fn (Model $record): bool => $record instanceof ProductionOrder && $record->status->canRelease());
    }

    public function complete(User $user, Model $record): bool
    {
        return $this->allowsDomainAction($user, $record, 'complete', static function (Model $record): bool {
            if ($record instanceof ProductionOrder) {
                return in_array($record->status->value, ['released', 'in_progress'], true);
            }

            return $record instanceof ProductionOrderOperation
                && $record->status === ProductionOrderOperationStatus::InProgress;
        });
    }

    public function cancel(User $user, Model $record): bool
    {
        return $this->allowsDomainAction($user, $record, 'cancel', static fn (Model $record): bool => $record instanceof ProductionOrder && $record->status->canCancel());
    }

    public function recordConsumption(User $user, Model $record): bool
    {
        return $this->allowsDomainAction($user, $record, 'record_consumption', static fn (Model $record): bool => $record instanceof ProductionOrder
            && in_array($record->status->value, ['released', 'in_progress'], true));
    }

    public function start(User $user, Model $record): bool
    {
        return $this->allowsDomainAction($user, $record, 'start', static fn (Model $record): bool => $record instanceof ProductionOrderOperation && $record->status->canStart());
    }

    public function skip(User $user, Model $record): bool
    {
        return $this->allowsDomainAction($user, $record, 'skip', static fn (Model $record): bool => $record instanceof ProductionOrderOperation && $record->status !== ProductionOrderOperationStatus::Completed);
    }

    public function execute(User $user, Model $record): bool
    {
        return $this->allowsDomainAction($user, $record, 'execute', static fn (Model $record): bool => $record instanceof QualityCheck);
    }

    public function resolve(User $user, Model $record): bool
    {
        return $this->allowsDomainAction($user, $record, 'resolve', static fn (Model $record): bool => $record instanceof NonConformance && $record->status->isActionable());
    }

    public function close(User $user, Model $record): bool
    {
        return $this->allowsDomainAction($user, $record, 'close', static function (Model $record): bool {
            if ($record instanceof NonConformance) {
                return $record->status === NonConformanceStatus::Resolved;
            }

            return $record instanceof Downtime && $record->ended_at === null;
        });
    }

    public function explode(User $user, Model $record): bool
    {
        return $this->allowsDomainAction($user, $record, 'explode', static fn (Model $record): bool => $record instanceof Bom);
    }

    public function forwardTrace(User $user, Model $record): bool
    {
        return $this->allowsDomainAction($user, $record, 'forward_trace', static fn (Model $record): bool => $record instanceof LotNumber);
    }

    public function backwardTrace(User $user, Model $record): bool
    {
        return $this->allowsDomainAction($user, $record, 'backward_trace', static fn (Model $record): bool => $record instanceof LotNumber);
    }

    /**
     * @param  callable(Model): bool  $state_allows
     */
    private function allowsDomainAction(User $user, Model $record, string $operation, callable $state_allows): bool
    {
        if (! $state_allows($record)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->hasPermission($user, $record, $operation);
    }

    private function hasPermission(User $user, Model $record, string $operation): bool
    {
        $permission = PermissionName::forModel($record, $operation);

        if (! Permission::query()->where('name', $permission)->exists()) {
            return false;
        }

        $guard = config('auth.defaults.guard');

        return $user->hasPermissionTo($permission, is_string($guard) ? $guard : 'web');
    }
}
