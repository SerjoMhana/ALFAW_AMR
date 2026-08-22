<?php

/**
 * Nightly backups.
 *
 * A school's records are its year of work: marks entered over four quarters,
 * receipts, registers. Losing them is not an inconvenience, so the settings
 * here favour keeping too much over keeping too little.
 */
return [
    // Where the archives are written. Somewhere outside the project is better
    // still — another disk, a synced folder — since a backup on the same disk
    // as the database only survives mistakes, not hardware.
    'path' => env('BACKUP_PATH', storage_path('app/private/backups')),

    // How many days of archives to keep. Older ones are removed after each run.
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 30),

    /*
     * mysqldump. XAMPP ships it beside the server; a Linux host usually has it
     * on the PATH. Left empty, the backup falls back to reading the tables
     * through PHP, which works everywhere but is slower.
     */
    'mysqldump' => env('BACKUP_MYSQLDUMP', 'C:\\xampp\\mysql\\bin\\mysqldump.exe'),

    /*
     * Uploaded files: classroom attachments, the report card logo. The database
     * alone would restore a school whose every attachment is a broken link.
     */
    'files' => [
        'include' => [
            storage_path('app/private/classroom'),
            storage_path('app/public'),
        ],
        // Never fold previous backups into the next one.
        'exclude' => [
            env('BACKUP_PATH', storage_path('app/private/backups')),
        ],
    ],

    // The daily run. Set BACKUP_TIME to something outside school hours.
    'time' => env('BACKUP_TIME', '01:30'),
];
