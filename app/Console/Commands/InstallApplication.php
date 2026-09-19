<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * First-time installation on a host with no shell access.
 *
 * WHY THIS IS SEPARATE FROM `attendance:deploy`
 *
 * A deploy runs repeatedly and must be safe to run unattended. An install runs ONCE and does
 * things that would be destructive if repeated:
 *
 *  - `migrate` on an empty database;
 *  - `db:seed`, which creates the owner account;
 *  - `key:generate`, which sets `APP_KEY`.
 *
 * Folding these into the deploy command would mean every routine deploy ran them again, and
 * `key:generate` alone would be catastrophic: `APP_KEY` decrypts the employee IC numbers, so
 * regenerating it makes every stored IC permanently unreadable — silently, because the console
 * simply shows a number that can never be revealed.
 *
 * So install is a deliberately separate, deliberately one-shot command that REFUSES to run on
 * an application that is already installed.
 *
 * HOW IT IS TRIGGERED WITHOUT A SHELL
 *
 * cPanel → Cron Jobs, a one-off job running this command, then delete the job. It is not
 * reachable over HTTP: an internet-facing installer is the classic way a fresh Laravel app is
 * taken over, and this one would create an owner account.
 */
class InstallApplication extends Command
{
    protected $signature = 'attendance:install
                            {--seed : Also create the default outlets, owner and manager}
                            {--force : Proceed even if the application looks already installed}';

    protected $description = 'First-time install: generate the key, migrate, and optionally seed';

    public function handle(): int
    {
        $this->line('Environment: '.app()->environment());
        $this->line('Database:    '.(string) config('database.default'));

        /*
         * First, because everything after it — including `key:generate` writing nothing but
         * the .env, and the log writer — assumes these directories exist. A fresh server
         * reached over FTP does not have them: the deploy deliberately does not upload
         * anything under storage/, which is what protects the photographs and sessions.
         */
        $this->ensureStorageTree();

        if ($this->looksInstalled() && ! $this->option('force')) {
            $this->newLine();
            $this->error('This application already looks installed — refusing to run.');
            $this->newLine();
            $this->line('Re-running an install would regenerate APP_KEY, making every stored IC');
            $this->line('number permanently unreadable, and would recreate the published default');
            $this->line('accounts. Use `attendance:deploy` for routine updates.');
            $this->newLine();
            $this->line('If you are certain, pass --force.');

            return self::FAILURE;
        }

        // ---- APP_KEY -------------------------------------------------------

        $key = (string) config('app.key');

        if ($key === '') {
            /*
             * Generated only when absent. An EXISTING key is never replaced: it decrypts the
             * IC numbers, and there is no way to recover them afterwards.
             */
            Artisan::call('key:generate', ['--force' => true]);
            $this->info('APP_KEY generated.');
        } else {
            $this->line('APP_KEY already set — left alone (regenerating it would destroy every encrypted IC number).');
        }

        // ---- Schema --------------------------------------------------------

        $code = Artisan::call('migrate', ['--force' => true]);
        $this->line(Artisan::output());

        if ($code !== 0) {
            $this->error('Migrations failed.');

            return self::FAILURE;
        }

        $this->info('Schema created.');

        // ---- Seed ----------------------------------------------------------

        if ($this->option('seed')) {
            $code = Artisan::call('db:seed', ['--force' => true]);
            $this->line(Artisan::output());

            if ($code !== 0) {
                $this->error('Seeding failed. The schema is intact; re-run without --seed, or fix and retry.');

                return self::FAILURE;
            }

            $this->info('Seeded.');

            /*
             * Printed with the warning attached, because these are the credentials most likely
             * to survive into production unexamined — they exist in the repository, so anyone
             * who has seen it can sign in and read every staff member's hours.
             */
            $this->newLine();
            $this->warn('The seeded accounts use the password "password", which is published in the repository.');
            $this->warn('Sign in and change them BEFORE staff use this site.');
        }

        // ---- Report --------------------------------------------------------

        $this->newLine();
        $this->line('---');

        try {
            $this->line('Outlets:   '.DB::table('outlets')->count());
            $this->line('Employees: '.DB::table('employees')->count());
            $this->line('Users:     '.DB::table('users')->count());
        } catch (\Throwable $e) {
            $this->error('Could not read back the tables: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Installation complete.');
        $this->newLine();
        $this->line('Next:');
        $this->line('  1. Delete this cron job from cPanel.');
        $this->line('  2. Sign in and change the seeded passwords.');
        $this->line('  3. Run attendance:deploy-check from the cron entry and read the output.');

        return self::SUCCESS;
    }

    /**
     * Whether the schema already exists.
     *
     * Presence of the `users` table is the signal: it is created by the default migrations, so
     * it means `migrate` has completed at least once on this database.
     */
    private function looksInstalled(): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable('users')
                && User::query()->exists();
        } catch (\Throwable) {
            // Cannot connect, or no tables: treat as not installed and let `migrate` decide.
            return false;
        }
    }

    /**
     * Create the storage directories the application needs.
     *
     * See `RunDeployment` for the full reasoning: the deploy does not upload anything under
     * `storage/` (that protects the photographs and sessions), so the empty directories have
     * to be created here. Without them the application dies with "View path not found", which
     * names views rather than the missing folder.
     */
    private function ensureStorageTree(): void
    {
        $directories = [
            'app/private/punches',
            'app/private/employees',
            'framework/cache/data',
            'framework/sessions',
            'framework/views',
            'logs',
        ];

        foreach ($directories as $directory) {
            $path = storage_path($directory);

            if (! is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }
}
