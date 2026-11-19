<?php
// Denzour DailyDesk settings. Edit this file with cPanel File Manager if needed.
return [
    'app_name'         => 'Denzour DailyDesk',
    'company'          => 'Denzour Nutrition',
    'timezone'         => 'Asia/Kolkata',

    // Needed only once, on the first-time setup screen. Keep it private.
    'install_key'      => 'CHANGE-ME-SETUP-KEY',

    // Employees can add or edit their own report for today and this many days back.
    'edit_window_days' => 7,

    // Weekly off days: 0 = Sunday, 1 = Monday ... 6 = Saturday.
    'weekly_off'       => [0],

    // Target for the share of expected daily reports that should be filed (percent).
    'report_target'    => 90,

    // Sign in with Google (optional). Leave both empty to hide the button.
    // Get them from Google Cloud Console > APIs & Services > Credentials (see README_GOOGLE.txt).
    'google_client_id'     => '',
    'google_client_secret' => '',

    // Hours limits.
    'max_hours_task'   => 16,
    'max_hours_day'    => 24,

    'designations' => [
        'SEO', 'Performance Marketing', 'Social Media', 'Design / Creative',
        'Content', 'E-commerce / Website', 'Sales', 'Operations',
        'Accounts', 'HR / Admin', 'Management', 'Other',
    ],
    'categories' => [
        'Planning', 'Execution', 'Meeting', 'Reporting', 'Research', 'Support', 'Other',
    ],

    'db_path' => __DIR__ . '/data/dailydesk.sqlite',
];
