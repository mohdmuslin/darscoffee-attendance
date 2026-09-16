<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#78350f">
    <meta name="description" content="Attendance management for Dars Coffee.">
    <title>Attendance — Management</title>

    {{--
        No service worker here, deliberately. The console is a live working
        surface — a cached timesheet would show a manager hours that are no longer
        true. Same reasoning as the ordering system's management app.
    --}}
    <link rel="icon" href="/favicon.png" type="image/png">

    @vite(['resources/css/app.css', 'resources/js/console/main.js'])
</head>
<body class="antialiased">
    <div id="console-app"></div>
</body>
</html>
