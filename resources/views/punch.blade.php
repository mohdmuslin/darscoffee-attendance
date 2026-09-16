<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#78350f">
    <meta name="description" content="Clock in and out for your shift at Dars Coffee.">
    <title>Clock in — Dars Coffee</title>

    {{--
        The punch app is the only surface staff use, so it gets the app-like
        treatment: standalone display and an install prompt on the home screen,
        which saves them finding a bookmark every shift.
    --}}
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/favicon.png" type="image/png">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">

    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Dars Attendance">

    @vite(['resources/css/app.css', 'resources/js/punch/main.js'])
</head>
<body class="antialiased">
    <div id="punch-app"></div>
</body>
</html>
