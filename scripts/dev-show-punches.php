<?php

/*
 * Local development helper: show what the last few punches actually recorded.
 *
 * Not part of the app. Written while verifying the offline queue, where the question is always
 * "what time did that punch land at, and was it marked as client-reported?" — which the punch
 * listing above did not answer.
 *
 *   php scripts/dev-show-punches.php [limit]
 */

require __DIR__.'/dev-bootstrap.php';

use App\Models\Anomaly;
use App\Models\PunchEvent;
use App\Models\TimeEntry;

$limit = (int) ($argv[1] ?? 10);

echo 'NOW='.now()->toIso8601String().PHP_EOL;
echo '--- entries (newest first) ---'.PHP_EOL;

foreach (TimeEntry::orderByDesc('id')->limit($limit)->get() as $entry) {
    echo sprintf(
        'id=%d started=%s ended=%s dur=%ss offline=%s bdate=%s photo=%s'.PHP_EOL,
        $entry->id,
        $entry->started_at?->toIso8601String() ?? '-',
        $entry->ended_at?->toIso8601String() ?? 'OPEN',
        $entry->durationSeconds(),
        $entry->is_offline_sync ? 'YES' : 'no',
        $entry->business_date?->toDateString() ?? '-',
        $entry->started_photo_path !== null ? 'yes' : 'no',
    );
}

echo '--- anomalies ---'.PHP_EOL;

foreach (Anomaly::orderByDesc('id')->limit($limit)->get() as $anomaly) {
    echo sprintf(
        '  entry=%d %s :: %s'.PHP_EOL,
        $anomaly->time_entry_id,
        $anomaly->type->value,
        $anomaly->detail ?? '-',
    );
}

echo '--- punch trail (newest first) ---'.PHP_EOL;

foreach (PunchEvent::orderByDesc('id')->limit($limit)->get() as $event) {
    echo sprintf(
        '  %s employee=%s uuid=%s meta=%s'.PHP_EOL,
        $event->event->value,
        $event->employee_id ?? '-',
        $event->client_uuid ?? '-',
        json_encode($event->meta ?? []),
    );
}
