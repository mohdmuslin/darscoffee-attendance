<?php

namespace App\Console\Commands;

use App\Services\PhotoRetentionService;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Pre-flight check for a live deployment.
 *
 * WHY THIS EXISTS
 *
 * Every item below is a misconfiguration that produces a WORKING-looking application with one
 * thing quietly broken. A single wrong setting does not crash the app — it makes the punch
 * screen demand a photograph nobody can take, or the photographs 403, or a forgotten clock-out
 * go unnoticed for a week, or a staff member be paid for hours they did not work. None of them
 * raise an error, and several of them look like something else entirely.
 *
 * So this is written to be run on the live host, immediately after deploying and again after any
 * .env change. It reports what it finds rather than fixing anything: a command that silently
 * repairs a production configuration is one nobody trusts twice.
 *
 * Exit code is non-zero when anything FAILS, so it can gate a deploy script.
 */
class DeployCheck extends Command
{
    protected $signature = 'attendance:deploy-check';

    protected $description = 'Verify production configuration before letting staff use the site';

    /** @var array<int, array{0: string, 1: string, 2: string}> [status, item, detail] */
    private array $results = [];

    public function handle(): int
    {
        $this->checkEnvironment();
        $this->checkDatabase();
        $this->checkStorage();
        $this->checkSignedUrls();
        $this->checkScheduledWork();
        $this->checkAccounts();
        $this->checkTimezone();

        $this->render();

        $failed = collect($this->results)->where(0, 'FAIL')->count();

        if ($failed > 0) {
            $this->newLine();
            $this->error($failed.' check(s) failed. Do not let staff use this site yet.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('All checks passed.');

        return self::SUCCESS;
    }

    private function record(string $status, string $item, string $detail): void
    {
        $this->results[] = [$status, $item, $detail];
    }

    // ---- Checks ------------------------------------------------------

    private function checkEnvironment(): void
    {
        $env = app()->environment();

        $this->record($env === 'production' ? 'PASS' : 'FAIL', 'APP_ENV', $env);

        /*
         * Debug mode on a live site prints database credentials and environment values to
         * whoever triggers an error page. This is the single most damaging setting here, which
         * is why it is a FAIL rather than a warning.
         */
        $debug = (bool) config('app.debug');

        $this->record(
            $debug ? 'FAIL' : 'PASS',
            'APP_DEBUG',
            $debug ? 'ON — error pages will leak credentials and secrets' : 'off',
        );

        $key = (string) config('app.key');

        $this->record(
            $key === '' ? 'FAIL' : 'PASS',
            'APP_KEY',
            $key === '' ? 'missing — encrypted IC numbers cannot be read' : 'set',
        );

        $url = (string) config('app.url');

        $this->record(
            str_starts_with($url, 'https://') ? 'PASS' : 'FAIL',
            'APP_URL',
            $url.' (must be the https host, or signed photo urls will not validate)',
        );

        /*
         * No worker process exists on this host, so a queued job would be written and never
         * run. `sync` executes it inline instead. Nothing dispatches jobs today, which is
         * exactly why the setting is worth pinning — the failure would appear the day somebody
         * adds one.
         */
        $queue = (string) config('queue.default');

        $this->record(
            $queue === 'sync' ? 'PASS' : 'FAIL',
            'QUEUE_CONNECTION',
            $queue === 'sync' ? 'sync' : $queue.' — no worker on this host, so jobs would never run',
        );

        $cache = (string) config('cache.default');

        $this->record(
            in_array($cache, ['database', 'file', 'array'], true) ? 'PASS' : 'FAIL',
            'CACHE_STORE',
            $cache.' (redis is not available on this host)',
        );
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();

            $this->record('PASS', 'Database', 'connected');

            $pending = $this->pendingMigrations();

            $this->record(
                $pending === [] ? 'PASS' : 'FAIL',
                'Migrations',
                $pending === [] ? 'all applied' : count($pending).' pending: '.implode(', ', array_slice($pending, 0, 3)),
            );
        } catch (\Throwable $e) {
            $this->record('FAIL', 'Database', 'cannot connect: '.$e->getMessage());
        }
    }

    /**
     * Migrations that exist as files but are not in the migrations table.
     *
     * Checked rather than assumed, because a deploy that forgets `migrate --force` boots fine
     * and then fails on the first request that touches a new column — which looks like a bug in
     * the code rather than a missed deploy step.
     *
     * @return array<int, string>
     */
    private function pendingMigrations(): array
    {
        $ran = DB::table('migrations')->pluck('migration')->all();

        $files = collect(glob(database_path('migrations/*.php')))
            ->map(fn (string $path) => pathinfo($path, PATHINFO_FILENAME))
            ->all();

        return array_values(array_diff($files, $ran));
    }

    private function checkStorage(): void
    {
        /*
         * Photographs live OUTSIDE the web root. If this path resolves inside `public` the
         * whole privacy model collapses: every staff photograph becomes fetchable by anyone who
         * guesses a filename, with no signature and no expiry.
         */
        $root = Storage::disk('local')->path('');

        $insidePublic = str_starts_with(
            str_replace('\\', '/', realpath($root) ?: $root),
            str_replace('\\', '/', realpath(public_path()) ?: public_path()),
        );

        $this->record(
            $insidePublic ? 'FAIL' : 'PASS',
            'Photo storage',
            $insidePublic ? 'inside the web root — photographs are publicly fetchable' : $root,
        );

        $writable = is_writable($root);

        $this->record(
            $writable ? 'PASS' : 'FAIL',
            'Photo storage writable',
            $writable ? 'yes' : 'no — every punch with a photo will fail',
        );
    }

    /**
     * The check that would have prevented a live-site failure.
     *
     * Behind a proxy that terminates TLS, PHP sees HTTPS requests as HTTP unless the proxy is
     * trusted. Signed photo URLs are then generated over http, redirect to https, and fail
     * validation — because the signature is checked against the REQUEST's scheme. The console
     * loads, the API works, and every staff photograph is refused.
     *
     * `APP_URL` being https is necessary but not sufficient: the request must also be SEEN as
     * secure. So the two are reported together, because either one alone can be right while the
     * pair is wrong.
     */
    private function checkSignedUrls(): void
    {
        $proxies = env('TRUSTED_PROXIES');

        /*
         * Whether config is cached changes HOW the setting must be supplied, and getting that
         * wrong is the most common reason "I changed the setting and nothing happened".
         *
         * With `config:cache` in place the `.env` file is not loaded on a request, so
         * `TRUSTED_PROXIES` must exist in the REAL environment — cPanel's cron entry and the
         * PHP-FPM pool — not only in `.env`. Reported because it is invisible otherwise: `.env`
         * shows the value, the app ignores it, and the check below reads the same null the app
         * does.
         */
        $cached = app()->configurationIsCached();

        $this->record(
            $cached ? 'WARN' : 'PASS',
            'Config cached',
            $cached
                ? 'YES — `.env` is NOT read on requests, so TRUSTED_PROXIES (and every other value) must be set in the real environment. Change a value here and you must also re-run config:cache.'
                : 'no — values are read from .env on each request',
        );

        $this->record(
            filled($proxies) ? 'PASS' : 'WARN',
            'TRUSTED_PROXIES',
            filled($proxies)
                ? (string) $proxies
                : 'not set — correct ONLY if this host terminates HTTPS itself. Behind a proxy or CDN, set it to * or the proxy range, or photographs will 403.',
        );

        try {
            $signed = URL::temporarySignedRoute('photos.show', now()->addMinutes(1), ['path' => 'test.jpg']);

            $this->record(
                str_starts_with($signed, 'https://') ? 'PASS' : 'FAIL',
                'Signed photo URL scheme',
                str_starts_with($signed, 'https://') ? 'https' : 'http — APP_URL must be the https host',
            );
        } catch (\Throwable $e) {
            $this->record('FAIL', 'Signed photo URL scheme', 'could not generate: '.$e->getMessage());
        }
    }

    /**
     * Cron must be installed, or two things quietly stop.
     *
     * The retention purge stops, which is a compliance gap nobody notices. The forgotten
     * clock-out check stops, which the article explains is worse: a segment left open blocks
     * that employee's next clock-in entirely, with no error shown at the screen.
     */
    private function checkScheduledWork(): void
    {
        $jobs = collect(app(Schedule::class)->events());

        $this->record(
            $jobs->count() > 0 ? 'PASS' : 'FAIL',
            'Scheduled jobs',
            $jobs->count().' registered',
        );

        foreach ($jobs as $job) {
            $this->record('PASS', '  • '.$job->description ?? 'scheduled command', $job->expression);
        }

        $this->record(
            'WARN',
            'Cron installed?',
            'this command cannot see the crontab. Verify separately that `schedule:run` runs every minute.',
        );
    }

    /**
     * The seeded accounts ship with the password "password", published in this repository.
     *
     * Reported rather than fixed: changing a password silently would lock out whoever is using
     * the account. It is listed as a FAIL because anyone who has seen the repo can sign in.
     */
    private function checkAccounts(): void
    {
        try {
            $default = DB::table('users')
                ->whereIn('email', ['owner@darscoffee.com', 'fatimahbokhare@gmail.com'])
                ->pluck('email');

            foreach ($default as $email) {
                $this->record(
                    'FAIL',
                    'Seeded account',
                    $email.' — still present, and its published default password may still be in use. Sign in and change it, then re-run this check.',
                );
            }

            if ($default->isEmpty()) {
                $this->record('PASS', 'Seeded accounts', 'none of the published default accounts remain');
            }
        } catch (\Throwable $e) {
            $this->record('WARN', 'Seeded accounts', 'could not check: '.$e->getMessage());
        }
    }

    private function checkTimezone(): void
    {
        /*
         * The application runs in UTC and the business does not. A misconfigured display timezone
         * shifts every reported time by eight hours, which reads as corrupted data rather than a
         * setting — and is why a forgotten clock-out is reported in the business timezone.
         */
        $appTz = (string) config('app.timezone');
        $businessTz = (string) config('attendance.business_timezone');

        $this->record($appTz === 'UTC' ? 'PASS' : 'WARN', 'app.timezone', $appTz);
        $this->record(
            $businessTz !== '' ? 'PASS' : 'FAIL',
            'attendance.business_timezone',
            $businessTz,
        );

        $this->record(
            'WARN',
            'Server clock',
            'server time is '.now()->toIso8601String().'. Confirm it matches the host clock; a drifting server mis-stamps every punch.',
        );

        $this->record(
            'PASS',
            'Retention window',
            app(PhotoRetentionService::class)->retentionDays().' days',
        );
    }

    // ---- Output ------------------------------------------------------

    private function render(): void
    {
        $this->newLine();
        $this->line('Deployment checks');
        $this->newLine();

        $colors = [
            'PASS' => 'fg=green',
            'WARN' => 'fg=yellow',
            'FAIL' => 'fg=red',
        ];

        foreach ($this->results as [$status, $item, $detail]) {
            $this->line(sprintf(
                '  <%s>%-4s</> %-32s %s',
                $colors[$status],
                $status,
                $item,
                $detail,
            ));
        }
    }
}
