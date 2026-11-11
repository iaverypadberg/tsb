<?php

namespace App\Policies;

use App\Actions\Visitors\CurrentVisitor;
use App\Models\Route;
use App\Models\User;

class RoutePolicy
{
    public function __construct(private CurrentVisitor $visitor) {}

    /**
     * Determine whether the user can view the route.
     *
     * Three ways in, in this order. An expired route is nobody's to read,
     * including the person who uploaded it: it said when it would go away and
     * that has to hold whether or not the pruner has been round since. A
     * shared route is anybody's, which is what sharing means. Otherwise it is
     * the owner's alone — and the owner may have no account, in which case
     * they are the session that uploaded it, so the user is optional here.
     */
    public function view(?User $user, Route $route): bool
    {
        if ($route->hasExpired()) {
            return false;
        }

        if ($route->isShared()) {
            return true;
        }

        return $this->owns($user, $route);
    }

    /**
     * Determine whether the user can share the route, or stop sharing it.
     *
     * Owners only, with or without an account: being sent a link is not a
     * licence to pass it on from inside the app. Deliberately not tied to
     * `update`, which wants a real account.
     */
    public function share(?User $user, Route $route): bool
    {
        return ! $route->hasExpired() && $this->owns($user, $route);
    }

    /**
     * Determine whether the user can update the route.
     */
    public function update(?User $user, Route $route): bool
    {
        return $user !== null && $this->owns($user, $route);
    }

    /**
     * Determine whether the user can delete the route.
     *
     * Deleting is for riders with an account. A guest has nothing to manage:
     * they upload one route, read it, and either sign up or leave.
     */
    public function delete(?User $user, Route $route): bool
    {
        return $user !== null && $this->owns($user, $route);
    }

    /**
     * Routes are private to whoever uploaded them.
     *
     * Ownership is about who put the route there, never about who was handed
     * the link, so nothing here consults `shared_at`.
     */
    private function owns(?User $user, Route $route): bool
    {
        if (! $route->isGuestUpload()) {
            return $user?->id === $route->user_id;
        }

        $token = $this->visitor->token();

        return $token !== null && $token === $route->guest_token;
    }
}
