<?php

namespace App\Policies;

use App\Models\RoadFlag;
use App\Models\User;

class RoadFlagPolicy
{
    /**
     * Determine whether the user can read the flags waiting to be reviewed.
     */
    public function review(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can see this flag.
     *
     * Riders see their own; admins see every one of them.
     */
    public function view(User $user, RoadFlag $flag): bool
    {
        return $user->isAdmin() || $user->id === $flag->user_id;
    }

    /**
     * Determine whether the user can withdraw this flag.
     *
     * Only while nobody has acted on it: once a flag has been accepted it is
     * part of a road's score, and taking it back is a review decision.
     */
    public function delete(User $user, RoadFlag $flag): bool
    {
        return $user->id === $flag->user_id && $flag->isPending();
    }
}
