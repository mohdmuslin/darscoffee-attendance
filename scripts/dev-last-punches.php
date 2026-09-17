<?php

/*
 * Local development helper: dump recent punches, anomalies, sessions and stored photos.
 *
 *   php scripts/dev-last-punches.php [count]
 *
 * Not part of the app.
 */

require __DIR__.'/dev-bootstrap.php';

use App\Models\Anomaly;
use App\Models\PunchSession;
use App\Models\TimeEntry;

$limit = (int) ($argv[1] ?? 6);

echo '--- ENTRIES ---'.PHP_EOL;

foreach (TimeEntry::with(['employee', 'outlet'])->latest('id')->take($limit)->get() as $entry) {
    echo sprintf(
        '#%d %s %s %s->%s dur=%ds status=%s biz=%s'.PHP_EOL,
        $entry->id,
        $entry->employee?->name ?? '-',
        strtoupper($entry->type->value),
        $entry->started_at?->format('H:i:s') ?? '-',
        $entry->ended_at?->format('H:i:s') ?? 'open',
        $entry->durationSeconds(),
        $entry->status->value,
        $entry->business_date?->toDateString() ?? '-',
    );

    echo '   start_photo='.($entry->started_photo_path ?? 'NULL').PHP_EOL;
    echo '   end_photo='.($entry->ended_photo_path ?? 'NULL').PHP_EOL;
}

echo '--- ANOMALIES ---'.PHP_EOL;

foreach (Anomaly::latest('id')->take($limit)->get() as $anomaly) {
    echo sprintf(
        '#%d entry=%s %s note=%s'.PHP_EOL,
        $anomaly->id,
        $anomaly->time_entry_id ?? '-',
        $anomaly->type->value,
        $anomaly->note ?? '',
    );
}

echo '--- SESSIONS ---'.PHP_EOL;

foreach (PunchSession::with(['employee', 'outlet'])->latest('id')->take(5)->get() as $session) {
    echo sprintf(
        '#%d %s @ %s expires=%s used=%s'.PHP_EOL,
        $session->id,
        $session->employee?->name ?? '-',
        $session->outlet?->name ?? '-',
        $session->expires_at?->format('H:i:s') ?? '-',
        $session->last_used_at?->format('H:i:s') ?? 'never',
    );
}

echo '--- PHOTOS ON DISK ---'.PHP_EOL;

$root = storage_path('app/private');

foreach (scandir($root) as $folder) {
    if (in_array($folder, ['.', '..'], true)) {
        continue;
    }

    $path = $root.DIRECTORY_SEPARATOR.$folder;

    if (! is_dir($path)) {
        continue;
    }

    foreach (scandir($path) as $file) {
        if (in_array($file, ['.', '..'], true)) {
            continue;
        }

        echo $folder.'/'.$file.' ('.filesize($path.DIRECTORY_SEPARATOR.$file).' bytes)'.PHP_EOL;
    }
}
