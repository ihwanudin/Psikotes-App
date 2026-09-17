<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

trait ResolvesWebUser
{
    /** @param string|null $guard */
    public function user($guard = null): User
    {
        $user = parent::user($guard);

        if (! $user instanceof User) {
            throw new AuthorizationException('A web user is required.');
        }

        return $user;
    }
}
