<?php

namespace App\Actions\Routes;

use App\Models\Route;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Who an uploaded route belongs to.
 *
 * Either a rider with an account or a browser session that has not signed up
 * yet, never both. Named constructors are what keep that true, so nothing
 * downstream has to check it.
 */
class RouteOwner
{
    private function __construct(
        public ?int $userId,
        public ?string $guestToken,
    ) {}

    public static function rider(User $user): self
    {
        return new self(userId: $user->id, guestToken: null);
    }

    public static function guest(string $token): self
    {
        return new self(userId: null, guestToken: $token);
    }

    public function isGuest(): bool
    {
        return $this->userId === null;
    }

    /**
     * Where this owner's original GPX files are kept.
     *
     * A guest's uploads are kept under their token rather than loose in one
     * directory, so claiming or pruning them is a matter of one folder.
     */
    public function storageDirectory(): string
    {
        return $this->isGuest()
            ? 'routes/guests/'.$this->guestToken
            : 'routes/'.$this->userId;
    }

    /**
     * What owning a route means on the row itself.
     *
     * A guest upload is born counting down; a rider's has no end date. The
     * database holds the two together, so this is the only place that
     * decides which of them a new route gets.
     *
     * @return array{user_id: int|null, guest_token: string|null, expires_at: CarbonInterface|null}
     */
    public function attributes(): array
    {
        return [
            'user_id' => $this->userId,
            'guest_token' => $this->guestToken,
            'expires_at' => $this->isGuest()
                ? Date::now()->addDays(Route::GUEST_LIFETIME_DAYS)
                : null,
        ];
    }
}
