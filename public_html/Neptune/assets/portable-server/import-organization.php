<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$snapshotFile = (string)($argv[1] ?? '');
$configFile = (string)($argv[2] ?? '');
if ($snapshotFile === '' || $configFile === '' || !is_file($snapshotFile) || !is_file($configFile)) {
    fwrite(STDERR, "Usage: php import-organization.php /path/to/organization.json /path/to/config.php\n");
    exit(1);
}

/**
 * Read just enough of Neptune's generated snapshot to find the beginning of
 * the "tables" object, then stream one JSON row at a time. This avoids
 * file_get_contents()+json_decode() materializing the whole organization in
 * PHP memory.
 */
function neptune_find_tables_offset(string $file): int
{
    $fh = fopen($file, 'rb');
    if (!$fh) throw new RuntimeException('Could not open portable organization snapshot.');

    $head = fread($fh, 65536);
    if ($head === false || strpos($head, '"format":"neptune-portable-organization-v1"') === false) {
        fclose($fh);
        throw new RuntimeException('Portable organization snapshot format is invalid.');
    }
    rewind($fh);

    $marker = '"tables":{';
    $markerLen = strlen($marker);
    $tail = '';
    $offset = 0;

    while (!feof($fh)) {
        $chunk = fread($fh, 1024 * 1024);
        if ($chunk === false) {
            fclose($fh);
            throw new RuntimeException('Could not read portable organization snapshot.');
        }
        if ($chunk === '') break;

        $haystack = $tail . $chunk;
        $pos = strpos($haystack, $marker);
        if ($pos !== false) {
            $absolute = $offset - strlen($tail) + $pos + $markerLen;
            fclose($fh);
            return $absolute;
        }

        $offset += strlen($chunk);
        $tail = substr($haystack, -max(1, $markerLen - 1));
    }

    fclose($fh);
    throw new RuntimeException('Portable organization snapshot has no tables section.');
}

final class NeptuneJsonStream
{
    /** @var resource */
    private $fh;
    private string $buffer = '';
    private int $pos = 0;
    private bool $eof = false;

    public function __construct(string $file, int $offset)
    {
        $this->fh = fopen($file, 'rb');
        if (!$this->fh) throw new RuntimeException('Could not open snapshot stream.');
        if (fseek($this->fh, $offset) !== 0) {
            fclose($this->fh);
            throw new RuntimeException('Could not seek snapshot stream.');
        }
    }

    public function __destruct()
    {
        if (is_resource($this->fh)) fclose($this->fh);
    }

    private function refill(): bool
    {
        if ($this->eof) return false;
        $chunk = fread($this->fh, 1024 * 1024);
        if ($chunk === false) throw new RuntimeException('Could not read snapshot stream.');
        if ($chunk === '') {
            $this->eof = true;
            return false;
        }
        $this->buffer = $chunk;
        $this->pos = 0;
        return true;
    }

    public function char(): ?string
    {
        if ($this->pos >= strlen($this->buffer) && !$this->refill()) return null;
        return $this->buffer[$this->pos++];
    }

    public function peek(): ?string
    {
        if ($this->pos >= strlen($this->buffer) && !$this->refill()) return null;
        return $this->buffer[$this->pos] ?? null;
    }

    public function skipWhitespace(): void
    {
        while (($c = $this->peek()) !== null && ($c === ' ' || $c === "\n" || $c === "\r" || $c === "\t")) {
            $this->pos++;
        }
    }

    public function expect(string $expected): void
    {
        $this->skipWhitespace();
        $c = $this->char();
        if ($c !== $expected) {
            throw new RuntimeException("Malformed portable snapshot: expected {$expected}.");
        }
    }

    public function readString(): string
    {
        $this->skipWhitespace();
        if ($this->char() !== '"') throw new RuntimeException('Malformed portable snapshot string.');

        $raw = '"';
        $escaped = false;
        while (($c = $this->char()) !== null) {
            $raw .= $c;
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($c === '\\') {
                $escaped = true;
                continue;
            }
            if ($c === '"') {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (!is_string($decoded)) throw new RuntimeException('Malformed table name.');
                return $decoded;
            }
        }
        throw new RuntimeException('Unexpected end of snapshot string.');
    }

    public function readObject(): array
    {
        $this->skipWhitespace();
        if ($this->char() !== '{') throw new RuntimeException('Malformed portable snapshot row.');

        $raw = '{';
        $depth = 1;
        $inString = false;
        $escaped = false;

        while (($c = $this->char()) !== null) {
            $raw .= $c;

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($c === '\\') {
                    $escaped = true;
                } elseif ($c === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($c === '"') {
                $inString = true;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    $row = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($row)) throw new RuntimeException('Portable snapshot row is not an object.');
                    return $row;
                }
            }
        }

        throw new RuntimeException('Unexpected end of portable snapshot row.');
    }
}

function neptune_table_writer(PDO $pdo, string $table, array $row): array
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        throw new RuntimeException('Unsafe portable table name.');
    }

    $exists = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
    );
    $exists->execute([$table]);
    if ((int)$exists->fetchColumn() === 0) {
        throw new RuntimeException(
            'Portable schema is missing table required by the organization snapshot: ' . $table
        );
    }

    $valid = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?
         ORDER BY ORDINAL_POSITION'
    );
    $valid->execute([$table]);
    $allowed = array_flip(array_map('strval', $valid->fetchAll(PDO::FETCH_COLUMN)));

    $columns = array_values(array_filter(
        array_keys($row),
        static fn($c) => isset($allowed[$c]) && preg_match('/^[A-Za-z0-9_]+$/', (string)$c)
    ));
    if (!$columns) {
        throw new RuntimeException(
            'Portable schema has no compatible columns for snapshot table: ' . $table
        );
    }

    $quoted = array_map(static fn($c) => '`' . $c . '`', $columns);
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $updates = implode(',', array_map(
        static fn($c) => '`' . $c . '`=VALUES(`' . $c . '`)',
        $columns
    ));

    $sql = 'INSERT INTO `' . $table . '` (' . implode(',', $quoted) . ')
            VALUES (' . $placeholders . ')
            ON DUPLICATE KEY UPDATE ' . $updates;

    return [$pdo->prepare($sql), $columns];
}

$config = require $configFile;
$db = $config['db'] ?? [];
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host'] ?? '127.0.0.1',
    (int)($db['port'] ?? 3306),
    $db['name'] ?? 'neptune_portable',
    $db['charset'] ?? 'utf8mb4'
);
$pdo = new PDO($dsn, (string)($db['user'] ?? ''), (string)($db['pass'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$offset = neptune_find_tables_offset($snapshotFile);
$stream = new NeptuneJsonStream($snapshotFile, $offset);

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$total = 0;

try {
    while (true) {
        $stream->skipWhitespace();
        $next = $stream->peek();

        if ($next === null) throw new RuntimeException('Unexpected end of tables section.');
        if ($next === '}') {
            $stream->char();
            break;
        }
        if ($next === ',') {
            $stream->char();
            continue;
        }

        $table = $stream->readString();
        $stream->expect(':');
        $stream->expect('[');

        $stmt = null;
        $columns = [];
        $count = 0;

        while (true) {
            $stream->skipWhitespace();
            $next = $stream->peek();

            if ($next === null) throw new RuntimeException("Unexpected end of table {$table}.");
            if ($next === ']') {
                $stream->char();
                break;
            }
            if ($next === ',') {
                $stream->char();
                continue;
            }
            if ($next !== '{') throw new RuntimeException("Malformed row in table {$table}.");

            $row = $stream->readObject();

            if ($stmt === null) {
                [$stmt, $columns] = neptune_table_writer($pdo, $table, $row);
                if (!$stmt) {
                    throw new RuntimeException(
                        'Portable schema could not prepare snapshot table: ' . $table
                    );
                }
            }

            if ($stmt) {
                $values = [];
                foreach ($columns as $column) $values[] = $row[$column] ?? null;
                $stmt->execute($values);
                $count++;
                $total++;

                if (($count % 1000) === 0) {
                    fwrite(STDOUT, "  {$table}: {$count} rows...\n");
                }
            }
        }

        fwrite(STDOUT, "Imported {$table}: {$count}\n");
    }
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

fwrite(STDOUT, "Portable organization snapshot import complete. Total rows: {$total}\n");
