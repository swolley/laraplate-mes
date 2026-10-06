<?php

declare(strict_types=1);

namespace Modules\MES\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\User;
use Modules\MES\Policies\MesModelPolicy;

/**
 * Header actions are shown by the same policy as the domain actions of the generic CRUD (state guard
 * plus seeded permission). It is called directly: the gate would let a superadmin past the state guard.
 */
trait ChecksMesPolicy
{
    private function policyAllows(string $ability, mixed $record): bool
    {
        $user = auth()->user();

        return $record instanceof Model
            && $user instanceof User
            && resolve(MesModelPolicy::class)->{$ability}($user, $record);
    }
}
