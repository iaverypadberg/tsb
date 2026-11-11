<?php

namespace App\Actions\Routes;

use App\Models\Route;
use App\Models\User;

/**
 * Hands a guest's uploads to the account they have just signed into.
 *
 * This is what makes "create an account to keep this" true: without it the
 * rider would come back from registering to a page they no longer own.
 *
 * The original GPX files are left where they were uploaded. The path is
 * recorded on the route itself, so moving them would buy nothing but a window
 * in which a half-moved route points at a file that is no longer there.
 */
class ClaimGuestRoutes
{
    /**
     * @return int How many routes were claimed.
     */
    public function handle(User $user, ?string $guestToken): int
    {
        if ($guestToken === null) {
            return 0;
        }

        return Route::query()
            ->whereNull('user_id')
            ->where('guest_token', $guestToken)
            ->update([
                'user_id' => $user->id,
                'guest_token' => null,
                // Signing up is the promise that the route stops being
                // temporary, so the countdown comes off it here.
                'expires_at' => null,
            ]);
    }
}
