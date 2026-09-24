#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Neptune bulk admin creator
 *
 * Default: DRY RUN (no database changes)
 * Apply:   php add_neptune_admins.php --apply
 * Optional organization:
 *          php add_neptune_admins.php --apply --org=1
 *
 * Reads Neptune DB credentials from /etc/scout/db.env.
 */

const TEMP_PASSWORD = 'password1234';
const ENV_FILE = '/etc/scout/db.env';

$people = [
    ["Aahana Basappa", "aahana.b@mckinneysteamacademy.org", null],
    ["Abhi Batchu", "abhi.b@mckinneysteamacademy.org", null],
    ["Brady Hargraves", "brady.h@mckinneysteamacademy.org", null],
    ["Coach Armaan Kakkar", "armaan.k@mckinneysteamacademy.org", null],
    ["Coach Carson Dahlberg", "carson.d@mckinneysteamacademy.org", null],
    ["Coach Charlie Goodman", "charles@mckinneysteamacademy.org", null],
    ["Coach Darrin Gearheart", "darrin.g@mckinneysteamacademy.org", null],
    ["Coach Ron Collins", "ron.c@mckinneysteamacademy.org", null],
    ["Cory Marsh", "cory.m@mckinneysteamacademy.org", null],
    ["Elliot Gearheart", "elliot.gh@mckinneysteamacademy.org", null],
    ["Emmit Gearheart", "emmit@mckinneysteamacademy.org", null],
    ["Evan Collins", "evan.c@mckinneysteamacademy.org", null],
    ["Grayson Tucker", "grayson.t@mckinneysteamacademy.org", null],
    ["Ibrahim Siddiqui", "ibrahim.s@mckinneysteamacademy.org", null],
    ["jackson liu", "jackson.l@mckinneysteamacademy.org", null],
    ["Jathin Battepati", "jathin.b@mckinneysteamacademy.org", null],
    ["Joshua Lee", "joshua.l@mckinneysteamacademy.org", null],
    ["Jude Marsh", "jude.m@mckinneysteamacademy.org", null],
    ["Kevin Ahr", "kevin.a@mckinneysteamacademy.org", null],
    ["Krish Bharadiya", "krish.b@mckinneysteamacademy.org", null],
    ["Mahi Chaudhari", "mahi.c@mckinneysteamacademy.org", null],
    ["Mayon Mageswaran", "mayon.m@mckinneysteamacademy.org", null],
    ["MSA Admin", "admin@mckinneysteamacademy.org", "msa.admin"],
    ["Nisith Rajapakshe Gamage", "nisith.rg@mckinneysteamacademy.org", null],
    ["Nitin Sathish", "nitin.s@mckinneysteamacademy.org", null],
    ["Pradyun Nimmagadda", "pradyun.n@mckinneysteamacademy.org", null],
    ["Rigvedh Datla", "rigvedh.d@mckinneysteamacademy.org", null],
    ["Sid Rao", "sid.r@mckinneysteamacademy.org", null],
    ["Srihan Yerram", "srihan@mckinneysteamacademy.org", null],
    ["Stella Marsh", "stella.m@mckinneysteamacademy.org", null],
    ["Yajat Parmar", "yajat.p@mckinneysteamacademy.org", null]
];

function fail(string $message, int $code = 1): never {
    fwrite(STDERR, "ERROR: $message\n");
    exit($code);
}

function loadEnvFile(string $path): array {
    if (!is_readable($path)) {
        fail("Cannot read $path. Run this script as root or a user allowed to read Neptune secrets.");
    }

    $env = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        fail("Could not read $path.");
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === "'" && $last === "'") || ($first === '"' && $last === '"')) {
                $value = substr($value, 1, -1);
            }
        }

        $env[$key] = $value;
    }

    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $required) {
        if (!array_key_exists($required, $env) || $env[$required] === '') {
            fail("$required is missing from $path.");
        }
    }

    return $env;
}

function preferredUsername(string $email, ?string $override): string {
    if ($override !== null && $override !== '') {
        return strtolower($override);
    }
    return strtolower((string)strtok($email, '@'));
}

function chooseAvailableUsername(PDO $pdo, int $orgId, string $preferred): string {
    $check = $pdo->prepare(
        'SELECT id FROM users WHERE organization_id = ? AND username = ? LIMIT 1'
    );

    $candidate = $preferred;
    $suffix = 2;

    while (true) {
        $check->execute([$orgId, $candidate]);
        if (!$check->fetchColumn()) {
            return $candidate;
        }
        $candidate = $preferred . '.' . $suffix++;
    }
}

$apply = in_array('--apply', $argv, true);
$orgArg = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--org=')) {
        $value = substr($arg, 6);
        if (!ctype_digit($value) || (int)$value < 1) {
            fail("Invalid --org value.");
        }
        $orgArg = (int)$value;
    }
}

$env = loadEnvFile(ENV_FILE);

$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=utf8mb4',
    $env['DB_HOST'],
    $env['DB_NAME']
);

try {
    $pdo = new PDO(
        $dsn,
        $env['DB_USER'],
        $env['DB_PASSWORD'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (Throwable $e) {
    fail('Database connection failed: ' . $e->getMessage());
}

$organizations = $pdo->query(
    'SELECT id, name, slug FROM organizations ORDER BY id'
)->fetchAll();

if (!$organizations) {
    fail('No organization exists in Neptune.');
}

if ($orgArg !== null) {
    $organization = null;
    foreach ($organizations as $org) {
        if ((int)$org['id'] === $orgArg) {
            $organization = $org;
            break;
        }
    }
    if ($organization === null) {
        fail("Organization ID $orgArg does not exist.");
    }
} elseif (count($organizations) === 1) {
    $organization = $organizations[0];
} else {
    echo "Multiple Neptune organizations exist. Re-run with --org=ID:\n";
    foreach ($organizations as $org) {
        printf(
            "  %d  %s (%s)\n",
            (int)$org['id'],
            (string)$org['name'],
            (string)$org['slug']
        );
    }
    exit(2);
}

$orgId = (int)$organization['id'];

echo "Neptune bulk admin import\n";
echo "Organization: " . $organization['name'] . " (ID $orgId)\n";
echo "People: " . count($people) . "\n";
echo "Role: admin\n";
echo "Temporary password: password1234\n";
echo "Force password change: YES\n";
echo "Mode: " . ($apply ? "APPLY" : "DRY RUN") . "\n\n";

$findByEmail = $pdo->prepare(
    'SELECT id, username, role FROM users
     WHERE organization_id = ? AND email = ? LIMIT 1'
);

$findByUsername = $pdo->prepare(
    'SELECT id, email, role FROM users
     WHERE organization_id = ? AND username = ? LIMIT 1'
);

$insert = $pdo->prepare(
    'INSERT INTO users
        (organization_id, username, email, password_hash, display_name,
         role, active, must_change_password, last_login_at,
         password_changed_at, created_at)
     VALUES
        (?, ?, ?, ?, ?, "admin", 1, 1, NULL, NULL, NOW())'
);

$update = $pdo->prepare(
    'UPDATE users
     SET display_name = ?,
         role = CASE WHEN role = "owner" THEN "owner" ELSE "admin" END,
         active = 1,
         password_hash = ?,
         must_change_password = 1,
         password_changed_at = NULL
     WHERE id = ?'
);

$plan = [];

foreach ($people as [$displayName, $email, $usernameOverride]) {
    $email = strtolower(trim($email));
    $preferred = preferredUsername($email, $usernameOverride);

    $findByEmail->execute([$orgId, $email]);
    $existingByEmail = $findByEmail->fetch();

    if ($existingByEmail) {
        $plan[] = [
            'action' => 'UPDATE',
            'id' => (int)$existingByEmail['id'],
            'username' => (string)$existingByEmail['username'],
            'email' => $email,
            'name' => $displayName,
            'existing_role' => (string)$existingByEmail['role'],
        ];
        continue;
    }

    $findByUsername->execute([$orgId, $preferred]);
    $usernameCollision = $findByUsername->fetch();

    $username = $preferred;
    if ($usernameCollision) {
        $username = chooseAvailableUsername($pdo, $orgId, $preferred);
    }

    $plan[] = [
        'action' => 'INSERT',
        'id' => null,
        'username' => $username,
        'email' => $email,
        'name' => $displayName,
        'existing_role' => null,
    ];
}

printf("%-8s %-20s %-42s %s\n", 'ACTION', 'USERNAME', 'EMAIL', 'DISPLAY NAME');
echo str_repeat('-', 102) . "\n";

foreach ($plan as $row) {
    printf(
        "%-8s %-20s %-42s %s%s\n",
        $row['action'],
        $row['username'],
        $row['email'],
        $row['name'],
        $row['existing_role'] === 'owner' ? ' [OWNER PRESERVED]' : ''
    );
}

if (!$apply) {
    echo "\nDRY RUN ONLY — no database changes were made.\n";
    echo "If this looks correct, run:\n";
    echo "  php " . basename(__FILE__) . " --apply";
    if ($orgArg !== null) {
        echo " --org=$orgId";
    }
    echo "\n";
    exit(0);
}

try {
    $pdo->beginTransaction();

    $inserted = 0;
    $updated = 0;

    foreach ($plan as $row) {
        $hash = password_hash(TEMP_PASSWORD, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('password_hash failed.');
        }

        if ($row['action'] === 'UPDATE') {
            $update->execute([
                $row['name'],
                $hash,
                $row['id'],
            ]);
            $updated++;
        } else {
            $insert->execute([
                $orgId,
                $row['username'],
                $row['email'],
                $hash,
                $row['name'],
            ]);
            $inserted++;
        }
    }

    $pdo->commit();

    echo "\nSUCCESS\n";
    echo "Inserted: $inserted\n";
    echo "Updated:  $updated\n";
    echo "All listed accounts are active and have admin access (existing owners stay owners).\n";
    echo "All listed accounts were assigned the temporary password and must change it at next login.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail('Nothing was committed. ' . $e->getMessage());
}
