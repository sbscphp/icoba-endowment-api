<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Weekly digest report
    |--------------------------------------------------------------------------
    |
    | Emailed once a week to admins with a PDF (analytics + overdue pledge
    | follow-up list) and a CSV of the overdue list attached. Each report covers
    | the most recently completed Monday–Sunday week. Times are in the
    | application timezone (config('app.timezone')).
    |
    */

    'weekly_digest' => [
        'enabled' => (bool) env('WEEKLY_DIGEST_ENABLED', true),

        /** Day of the week (monday … sunday) the scheduler sends the digest. */
        'send_day' => env('WEEKLY_DIGEST_SEND_DAY', 'monday'),

        /** Time of day (HH:MM, app timezone) the scheduler sends the digest. */
        'send_at' => env('WEEKLY_DIGEST_SEND_AT', '07:00'),

        /** Number of weeks shown in the weekly donation trend (ending on the report week). */
        'trend_weeks' => (int) env('WEEKLY_DIGEST_TREND_WEEKS', 12),

        /** Number of months shown in the monthly donation trend (ending on the report month). */
        'trend_months' => (int) env('WEEKLY_DIGEST_TREND_MONTHS', 6),

        /** Installments due within this many days after the report week appear in the "upcoming" list. */
        'upcoming_days' => (int) env('WEEKLY_DIGEST_UPCOMING_DAYS', 7),

        /** Max donor groups printed in the PDF overdue section. The CSV always has every row. */
        'pdf_max_overdue_donors' => (int) env('WEEKLY_DIGEST_PDF_MAX_OVERDUE_DONORS', 200),

        /** Max rows in the smaller watch lists (upcoming, paused, new, fulfilled, bank transfers). */
        'pdf_max_list_rows' => (int) env('WEEKLY_DIGEST_PDF_MAX_LIST_ROWS', 50),

        /** Extra recipient addresses (comma separated) in addition to active admins. */
        'extra_recipients' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('WEEKLY_DIGEST_EXTRA_RECIPIENTS', ''))
        ))),

        /** Disk and directory where a copy of every generated digest is kept. */
        'storage_disk' => env('WEEKLY_DIGEST_STORAGE_DISK', 'local'),
        'storage_path' => env('WEEKLY_DIGEST_STORAGE_PATH', 'reports/weekly-digest'),
    ],

];
