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
        'base_url' => '/Neptune',
        'session_name' => 'NEPTUNESESSID',
        'timezone' => 'UTC',

        // The first organization created on a clean database normally receives
        // ID 1 and becomes the installation's platform organization.
        'platform_organization_id' => 1,

        // Fresh installs may always create the FIRST organization at
        // /Neptune/register.php. After that, this controls whether visitors may
        // create additional organizations without a platform-owner invitation.
        // Private/self-hosted team installs should normally leave this false.
        // Neptune's hosted multi-tenant service can set this true.
        'allow_public_registration' => false,

        // High-value platform-owner tools require strong authentication.
        //
        // strong = Google sign-in OR password + TOTP authenticator/recovery code.
        // google = Google sign-in only.
        //
        // Historical "auto" or "password" values are treated as "strong" so a
        // Platform Owner is never silently downgraded to password-only access.
        'platform_owner_auth' => 'strong',

        // Optional stable secret used to encrypt TOTP seeds at rest. Prefer a
        // random value here or NEPTUNE_MFA_ENCRYPTION_KEY in /etc/scout/neptune.env.
        // Example generator:
        //   php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
        //
        // If omitted, Neptune derives a compatibility key from the private DB
        // configuration. A dedicated stable key is recommended for new installs.
        'mfa_encryption_key' => '',
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
