<?php

use App\Models\AttendanceCorrection;

require __DIR__.'/dev-bootstrap.php';

// Clear pending corrections and any corrupted entries left by the smoke run.
$count = AttendanceCorrection::query()->where('status', 'pending')->delete();

echo "Deleted {$count} pending correction(s)".PHP_EOL;
