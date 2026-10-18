<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class GrantAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:grant-admin
        {email : The account to grant or revoke}
        {--revoke : Take the privilege away again}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Let an account read and act on the roads riders have flagged';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->components->error('No account with that email address.');

            return self::FAILURE;
        }

        $granting = ! $this->option('revoke');

        $user->forceFill(['is_admin' => $granting])->save();

        $this->components->info($granting
            ? "{$user->email} can now review flagged roads."
            : "{$user->email} can no longer review flagged roads.");

        return self::SUCCESS;
    }
}
