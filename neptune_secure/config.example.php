<?php
/**
 * Neptune configuration template.
 *
 * Copy this file to:
 *   neptune_secure/config.php
 *
 * Then replace the XXX values with your own environment settings.
 * Never commit config.php to a public repository.
 */
return [
    'app' => [
        // Use '' when Neptune is served at the site root; use '/Neptune' when mounted below it.
        'base_url' => '',
        'session_name' => 'NEPTUNESESSID',
        'timezone' => 'UTC',
        'trust_proxy' => false,
    ],

    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'XXX',
        'user' => 'XXX',
        'pass' => 'XXX',
        'charset' => 'utf8mb4',
    ],

    // Optional: only needed if you are importing data from an older Stat Owl /
    // legacy scouting database. Leave as XXX if you are not using the importer.
    'legacy_db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'XXX',
        'user' => 'XXX',
        'pass' => 'XXX',
        'charset' => 'utf8mb4',
    ],

    'tba' => [
        'auth_key' => 'XXX',
        'base_url' => 'https://www.thebluealliance.com/api/v3',
    ],

    // Optional public analytics source used by Pre-Scouting for EPA and season record.
    // No API key is required. Neptune continues working if Statbotics is unavailable.
    'statbotics' => [
        'base_url' => 'https://api.statbotics.io/v3',
    ],
];
