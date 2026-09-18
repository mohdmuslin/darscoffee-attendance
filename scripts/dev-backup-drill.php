<?php

/*
 * Backup and restore drill.
 *
 * Not part of the app. This exists because "we have backups" is a belief until somebody has
 * restored one, and a backup that has never been restored is not a backup — it is a file.
 *
 *   php scripts/dev-backup-drill.php                 # back up, restore into a scratch DB, verify
 *   php scripts/dev-backup-drill.php --keep          # leave the scratch database in place
 *   php scripts/dev-backup-drill.php --help
 *
 * WHAT THIS PROVES, AND WHAT IT DOES NOT
 *
 * It proves the DATABASE can be dumped and reloaded, and that the reloaded copy holds the same
 * rows. It deliberately does NOT touch the live database: the restore goes into a scratch
 * database that is dropped at the end, so running the drill is always safe.
 *
 * It also checks the thing that is easiest to forget: the photographs are FILES, not rows. A
 * database-only backup of this application restores a system that claims to have thousands of
 * punch photographs and can serve none of them — every timesheet would show a broken image and
 * there would be no way to settle a dispute, which is the only reason the photographs are
 * collected. So the dump is only half the artefact, and this drill says so.
 *
 * The cPanel target has no Redis and no queue worker, so nothing here depends on either.
 */

require __DIR__.'/dev-bootstrap.php';

use App\Models\Employee;
use App\Models\TimeEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$options = getopt('', ['keep', 'help']);

if (isset($options['help'])) {
    echo <<<'TXT'
    Backup and restore drill

      --keep   Leave the scratch database behind for inspection.
      --help   Show this message.

    Backs up the live database, restores it into a scratch database, and compares
    row counts. The live database is never written to.
    TXT.PHP_EOL;

    exit(0);
}

$keep = isset($options['keep']);

$host = config('database.connections.mysql.host', '127.0.0.1');
$port = config('database.connections.mysql.port', '3306');
$user = config('database.connections.mysql.username', 'root');
$pass = config('database.connections.mysql.password', '');
$live = config('database.connections.mysql.database');

$scratch = $live.'_restore_drill';
$dumpPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dars_attendance_drill.sql';

/*
 * Resolve the client binaries. Laragon keeps them outside the PATH, which is why every command in
 * this project has to spell PHP out in full; the same applies to mysqldump.
 */
$binDir = 'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64\\bin';
$mysqldump = is_file($binDir.'\\mysqldump.exe') ? $binDir.'\\mysqldump.exe' : 'mysqldump';
$mysql = is_file($binDir.'\\mysql.exe') ? $binDir.'\\mysql.exe' : 'mysql';

$passArg = $pass === '' ? '' : '--password='.escapeshellarg($pass);

/** Run a command and return [exitCode, output]. */
function run(string $command): array
{
    $output = [];
    $code = 0;

    exec($command.' 2>&1', $output, $code);

    return [$code, implode(PHP_EOL, $output)];
}

echo 'DATABASE='.$live.PHP_EOL;
echo 'SCRATCH='.$scratch.PHP_EOL;
echo 'DUMP='.$dumpPath.PHP_EOL;
echo '---'.PHP_EOL;

// ---- Step 1: dump ----------------------------------------------------------

$tables = collect(DB::select('SHOW TABLES'))
    ->map(fn ($row) => array_values((array) $row)[0])
    ->values();

echo 'Live tables: '.$tables->count().PHP_EOL;

$command = sprintf(
    '%s --host=%s --port=%s --user=%s %s --single-transaction --routines --events --skip-lock-tables %s > %s',
    escapeshellarg($mysqldump),
    escapeshellarg($host),
    escapeshellarg((string) $port),
    escapeshellarg($user),
    $passArg,
    escapeshellarg($live),
    escapeshellarg($dumpPath),
);

[$code, $output] = run($command);

if ($code !== 0) {
    echo 'DUMP FAILED (exit '.$code.')'.PHP_EOL.$output.PHP_EOL;
    exit(1);
}

$size = filesize($dumpPath);

echo 'Dumped '.number_format($size / 1024, 1).' KB'.PHP_EOL;

/*
 * `--single-transaction` matters for InnoDB: it gives a consistent snapshot without locking the
 * tables. Without it a punch arriving mid-dump can be captured half-written, and the restored copy
 * then fails a foreign key or, worse, succeeds with a segment that has no matching employee.
 */

// ---- Step 2: restore into a scratch database --------------------------------

$scratchCommand = sprintf(
    '%s --host=%s --port=%s --user=%s %s -e %s',
    escapeshellarg($mysql),
    escapeshellarg($host),
    escapeshellarg((string) $port),
    escapeshellarg($user),
    $passArg,
    escapeshellarg('DROP DATABASE IF EXISTS `'.$scratch.'`; CREATE DATABASE `'.$scratch.'`'),
);

[$code, $output] = run($scratchCommand);

if ($code !== 0) {
    echo 'COULD NOT CREATE THE SCRATCH DATABASE'.PHP_EOL.$output.PHP_EOL;
    exit(1);
}

echo 'Created scratch database.'.PHP_EOL;

$restoreCommand = sprintf(
    '%s --host=%s --port=%s --user=%s %s %s < %s',
    escapeshellarg($mysql),
    escapeshellarg($host),
    escapeshellarg((string) $port),
    escapeshellarg($user),
    $passArg,
    escapeshellarg($scratch),
    escapeshellarg($dumpPath),
);

[$code, $output] = run($restoreCommand);

if ($code !== 0) {
    echo 'RESTORE FAILED (exit '.$code.')'.PHP_EOL.$output.PHP_EOL;
    exit(1);
}

echo 'Restored into scratch.'.PHP_EOL;

// ---- Step 3: compare --------------------------------------------------------

/*
 * Row counts per table, compared between the two databases.
 *
 * Counted per table rather than as one total, because a single number hides the failure that
 * matters: every table matching except one. A restore that loses only `time_entries` still
 * "mostly works", and the loss is the entire attendance record.
 */
$mismatches = [];
$checked = 0;

foreach ($tables as $table) {
    $liveCount = DB::table($table)->count();

    $scratchCount = DB::connection('mysql')
        ->table($scratch.'.'.$table)
        ->count();

    $checked++;

    if ((int) $liveCount !== (int) $scratchCount) {
        $mismatches[] = sprintf('%s: live=%d restored=%d', $table, $liveCount, $scratchCount);
    }
}

echo '---'.PHP_EOL;
echo 'Compared '.$checked.' table(s).'.PHP_EOL;

if ($mismatches !== []) {
    echo 'MISMATCHES:'.PHP_EOL;

    foreach ($mismatches as $line) {
        echo '  '.$line.PHP_EOL;
    }

    echo 'RESTORE DRILL FAILED'.PHP_EOL;
} else {
    echo 'Every table matches. Database restore verified.'.PHP_EOL;
}

// ---- Step 4: the half a database dump cannot cover --------------------------

/*
 * Photographs are files. This is the check that catches the mistake everyone makes: dumping MySQL,
 * feeling safe, and discovering on the day it is needed that the photographs were never in the
 * database at all.
 */
$disk = Storage::disk('local');

$punchPhotos = count($disk->files('punches'));
$profilePhotos = count($disk->files('employees'));

$referred = 0;

foreach (TimeEntry::query()
    ->whereNotNull('started_photo_path')
    ->orWhereNotNull('ended_photo_path')
    ->cursor() as $entry) {
    $referred += ($entry->started_photo_path !== null ? 1 : 0);
    $referred += ($entry->ended_photo_path !== null ? 1 : 0);
}

echo '---'.PHP_EOL;
echo 'FILES, which a database dump does NOT include:'.PHP_EOL;
echo '  punch photos on disk:   '.$punchPhotos.PHP_EOL;
echo '  profile photos on disk: '.$profilePhotos.PHP_EOL;
echo '  photo paths in rows:    '.$referred.PHP_EOL;
echo '  employees with a photo: '.Employee::query()->whereNotNull('photo_path')->count().PHP_EOL;

if ($punchPhotos > 0) {
    echo PHP_EOL.'ACTION REQUIRED: back up storage/app/private as well. A database-only restore'.PHP_EOL;
    echo 'would give you a system that references '.$punchPhotos.' photograph(s) and can serve none'.PHP_EOL;
    echo 'of them — every timesheet showing a broken image, and no evidence to settle a dispute.'.PHP_EOL;
}

// ---- Clean up ---------------------------------------------------------------

@unlink($dumpPath);

if ($keep) {
    echo PHP_EOL.'--keep: scratch database '.$scratch.' left in place.'.PHP_EOL;
} else {
    [$code, $output] = run(sprintf(
        '%s --host=%s --port=%s --user=%s %s -e %s',
        escapeshellarg($mysql),
        escapeshellarg($host),
        escapeshellarg((string) $port),
        escapeshellarg($user),
        $passArg,
        escapeshellarg('DROP DATABASE IF EXISTS `'.$scratch.'`'),
    ));

    echo PHP_EOL.($code === 0 ? 'Scratch database dropped.' : 'Could not drop scratch: '.$output).PHP_EOL;
}

echo '---'.PHP_EOL;
echo 'DRILL '.($mismatches === [] ? 'PASSED' : 'FAILED').PHP_EOL;

exit($mismatches === [] ? 0 : 1);
