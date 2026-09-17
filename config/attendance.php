<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Business timezone
    |--------------------------------------------------------------------------
    |
    | The timezone the business thinks in, used wherever "today" is decided without a
    | specific outlet in hand — most importantly the default reporting window.
    |
    | This exists because the application runs in UTC and the business does not. With
    | the window derived from `now()` in UTC, every date after 16:00 UTC (midnight in
    | Kuala Lumpur) resolves to the PREVIOUS day, so "last 7 days" silently omitted the
    | shift currently in progress. A timesheet that hides today's work for a third of
    | every day is worse than no default at all.
    |
    | Per-outlet timezones still decide which business date a punch belongs to; this is
    | only the fallback for questions asked without an outlet.
    |
    */

    'business_timezone' => env('ATTENDANCE_TIMEZONE', 'Asia/Kuala_Lumpur'),

    /*
    |--------------------------------------------------------------------------
    | Reporting window
    |--------------------------------------------------------------------------
    |
    | Defaults for the timesheet screen. Kept in config rather than hard-coded in the
    | controller so the numbers are visible to an operator rather than buried in a
    | constant.
    |
    */

    'default_window_days' => 7,

    // A hard ceiling on one request, so an export cannot be turned into a scan of every
    // segment ever recorded on shared hosting.
    'max_window_days' => 92,

    // Rows in one page of the entry list.
    'entry_page_limit' => 1000,

];
