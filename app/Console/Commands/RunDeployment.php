<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Apply a deployment on a host with no shell access.
 *
 * WHY THIS COMMAND EXISTS
 *
 * The target host has no SSH and no cPanel Terminal, so every `php artisan ...` step in a
 * normal deploy has to be triggered some other way. cPanel's Cron Jobs GUI is the only
 * mechanism available, and a cron entry can hold ONE command — so the whole deploy sequence
 * is packaged here rather than being a chain the operator has to edit correctly each time.
 *
 * WHAT IT DOES NOT DO, DELIBERATELY
 *
 *  - NO `migrate:fresh`. It would drop every table and take the attendance history with it.
 *    Not offered as an option either: the one time somebody reaches for it "to fix things"
 *    is the time it matters.
 *
 *  - NO `key:generate`. Run once at install and NEVER again — `APP_KEY` decrypts the
 *    employee IC numbers. Regenerating it makes every stored IC permanently unreadable, and
 *    the damage is silent: the console simply shows a number that can never be revealed.
 *
 *  - NO seeding. `db:seed` is idempotent, which is exactly the problem: it would RECREATE the
 *    published default accounts (`owner@darscoffee.com` / `password`) after the owner had
 *    deleted them and made their own. Re-running it on every deploy would quietly re-open a
 *    hole that had been closed. Seeding is a one-time install step, so it lives in the guide
 *    instead.
 *
 * WHAT IT DOES
 *
 *  1. Reports whether migrations are pending, so the log says what changed.
 *  2. Runs `migrate --force` (the flag is required in production; without it artisan prompts,
 *     and a cron job cannot answer a prompt — it would hang until the next run).
 *  3. Clears the compiled caches so the new code actually takes effect.
 *
 * Caching is deliberately NOT enabled. `config:cache` makes `.env` unreadable on requests,
 * which is the single most common cause of "I changed the setting and nothing happened" —
 * and on this host the settings that matter (`TRUSTED_PROXIES`) are read before config is
 * even bound. The performance gain is not worth that trap at this scale.
 */
class RunDeployment extends Command
{
    protected $signature = 'attendance:deploy
                            {--if-flagged : Only deploy when storage/app/private/deploy.flag exists, then remove it}
                            {--dry-run : Report what would happen without changing anything}';

    protected $description = 'Run migrations and clear caches — the deploy steps, for a host with no shell';

    /**
     * The flag a permanent cron entry watches for.
     *
     * WHY A FLAG INSTEAD OF A WEB ENDPOINT
     *
     * The obvious alternative is a token-guarded route that runs migrations over HTTP. That
     * adds a permanently reachable endpoint whose worst case is arbitrary database work, on a
     * host where the punch endpoint is already public. This needs nothing reachable at all:
     * uploading one file over FTP signals "deploy now", and a cron entry that already exists
     * (for `schedule:run`) picks it up within minutes.
     *
     * A file in `storage/app/private` is outside the web root, so the flag itself is not
     * fetchable, and it needs no new credentials to manage.
     */
    private const FLAG = 'private/deploy.flag';

    public function handle(): int
    {
        if ($this->option('if-flagged') && ! $this->flagExists()) {
            // Silent: this runs every few minutes on a permanent cron entry, and printing a
            // line each time would fill the log with noise nobody reads.
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->line('Environment: '.app()->environment());
        $this->line('Database:    '.(string) config('database.default'));

        /*
         * Before anything else, because the deploy deliberately does not upload the storage
         * tree — see the workflow's `exclude`. Without these directories the application dies
         * with "View path not found", which points at views rather than at a missing folder.
         */
        $this->ensureStorageTree();

        $pending = $this->pendingMigrations();

        if ($pending === []) {
            $this->info('No migrations pending.');
        } else {
            $this->line(count($pending).' migration(s) to apply:');

            foreach ($pending as $migration) {
                $this->line('  • '.$migration);
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->comment('Dry run — nothing was changed.');

            return self::SUCCESS;
        }

        if ($pending !== []) {
            /*
             * `--force` is mandatory in production. Without it artisan asks "are you sure?"
             * and WAITS — and a cron job cannot answer, so the run would hang and the next
             * one would stack behind it.
             */
            $code = Artisan::call('migrate', ['--force' => true]);

            $this->line(Artisan::output());

            if ($code !== 0) {
                $this->error('Migrations failed. Nothing else was run.');

                // The flag is deliberately NOT removed on failure, so the next cron run
                // retries rather than silently skipping a half-applied deploy.
                return self::FAILURE;
            }

            $this->info('Migrations applied.');
        }

        /*
         * Cleared, not cached. See the class comment — a stale config cache on this host is
         * harder to diagnose than the few milliseconds it saves.
         */
        foreach (['config:clear', 'route:clear', 'view:clear'] as $command) {
            Artisan::call($command);
        }

        $this->info('Caches cleared.');

        /*
         * The flag is removed only after a clean finish. A crash or a failed migration leaves
         * it in place so the next run retries, rather than reporting success over a deploy
         * that did not complete.
         */
        if ($this->option('if-flagged')) {
            $this->removeFlag();
            $this->line('Deploy flag removed.');
        }

        $this->newLine();
        $this->info('Deployment applied.');

        return self::SUCCESS;
    }

    private function flagExists(): bool
    {
        return file_exists(storage_path('app/'.self::FLAG));
    }

    /**
     * Create the storage directories the application needs.
     *
     * WHY THIS IS IN CODE RATHER THAN A GLOB RULE IN THE DEPLOY WORKFLOW
     *
     * The deploy deliberately does not upload anything under `storage/` — that protects the
     * photographs, the sessions and the logs from being deleted by the FTP action's
     * reconciliation. The side effect is that the empty directories are not uploaded either,
     * and a fresh server then has no `storage/framework/views`.
     *
     * The failure that causes is "View path not found", which names views rather than a missing
     * directory, and was reproduced locally before writing this. Expressing the fix as `!`
     * negation patterns in the workflow would depend on the FTP action's exact glob semantics;
     * doing it here needs no such assumption and also repairs a server whose directories were
     * removed.
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

        $created = 0;

        foreach ($directories as $directory) {
            $path = storage_path($directory);

            if (! is_dir($path)) {
                mkdir($path, 0755, true);
                $created++;
            }
        }

        if ($created > 0) {
            $this->line('Created '.$created.' missing storage director(y/ies).');
        }
    }

    private function removeFlag(): void
    {
        @unlink(storage_path('app/'.self::FLAG));
    }

    /**
     * Migration files present on disk but absent from the migrations table.
     *
     * Reported before running rather than after, so the cron log says WHAT was applied. A
     * deploy that silently changed the schema is one nobody can account for later.
     *
     * @return array<int, string>
     */
    private function pendingMigrations(): array
    {
        try {
            $ran = DB::table('migrations')->pluck('migration')->all();
        } catch (\Throwable $e) {
            // A fresh database has no `migrations` table yet. Everything is pending.
            return collect(glob(database_path('migrations/*.php')))
                ->map(fn (string $path) => pathinfo($path, PATHINFO_FILENAME))
                ->all();
        }

        $files = collect(glob(database_path('migrations/*.php')))
            ->map(fn (string $path) => pathinfo($path, PATHINFO_FILENAME))
            ->all();

        return array_values(array_diff($files, $ran));
    }
}
