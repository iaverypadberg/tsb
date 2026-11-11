<?php

namespace App\Providers;

use App\Actions\Routes\ClaimGuestRoutes;
use App\Actions\Visitors\CurrentVisitor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->claimGuestUploadsOnLogin();
    }

    /**
     * Hand anything uploaded before signing up to the account that signed in.
     *
     * Hung off `Login` rather than `Registered` because it covers both: a
     * guest who registers is logged in as part of registering, and a guest who
     * already had an account and logged in instead meant the same thing by it.
     * Laravel keeps session data across the login's session regeneration, so
     * the token is still readable here.
     */
    protected function claimGuestUploadsOnLogin(): void
    {
        Event::listen(function (Login $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            $visitor = app(CurrentVisitor::class);

            app(ClaimGuestRoutes::class)->handle($event->user, $visitor->token());

            $visitor->forgetToken();
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
