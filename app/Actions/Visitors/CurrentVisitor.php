<?php

namespace App\Actions\Visitors;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * Who is doing something, when they may not have an account.
 *
 * A visitor without an account is known by a random token kept in their
 * session rather than by their IP address: an IP is shared by everyone behind
 * one router and changes when a phone moves between networks, so it can
 * neither keep two riders' uploads apart nor keep one rider's upload theirs.
 *
 * The token is only issued where something is about to be stored against it.
 * Reading never mints one, so a visitor who only ever looks at pages is not
 * handed an identity they did not need.
 */
class CurrentVisitor
{
    /**
     * Where the token sits in the session.
     */
    public const SESSION_KEY = 'visitor_token';

    /**
     * The token this visitor already carries, if any.
     */
    public function token(): ?string
    {
        $token = Session::get(self::SESSION_KEY);

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * The token this visitor carries, minting one if this is their first.
     */
    public function issueToken(): string
    {
        $token = $this->token();

        if ($token === null) {
            $token = (string) Str::uuid();

            Session::put(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /**
     * Forget the token, once whatever it owned has been claimed.
     */
    public function forgetToken(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    /**
     * What to count this visitor's attempts against.
     *
     * A signed in rider is counted as themselves. A guest is counted by
     * address, because the token they would otherwise be counted by is theirs
     * to throw away: clearing a cookie would clear the limit with it.
     */
    public function rateLimitKey(): string
    {
        return Auth::id() !== null
            ? 'user:'.Auth::id()
            : 'ip:'.Request::ip();
    }
}
