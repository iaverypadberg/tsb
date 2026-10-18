<?php

namespace App\Policies;

use App\Models\Route;
use App\Models\User;

class RoutePolicy
{
    /**
     * Determine whether the user can view the route.
     */
    public function view(User $user, Route $route): bool
    {
        return $this->owns($user, $route);
    }

    /**
     * Determine whether the user can update the route.
     */
    public function update(User $user, Route $route): bool
    {
        return $this->owns($user, $route);
    }

    /**
     * Determine whether the user can delete the route.
     */
    public function delete(User $user, Route $route): bool
    {
        return $this->owns($user, $route);
    }

    /**
     * Routes are private to the person who uploaded them.
     */
    private function owns(User $user, Route $route): bool
    {
        return $user->id === $route->user_id;
    }
}
