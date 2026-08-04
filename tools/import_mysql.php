<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/LegacyMysqlImporter.php';

function usage(): string
{
    return <<<'TXT'
Legacy-MySQL-Importer fuer die Wepro Zeiterfassung

Verwendung:
  php tools/import_mysql.php --inspect [--config=DATEI]
  php tools/import_mysql.php --dry-run [--config=DATEI]
  php tools/import_mysql.php --execute [--config=DATEI]

Optionen:
  --config=DATEI       Konfiguration, Standard: tools/mysql-import.php
  --target=DATEI       Ziel-SQLite-Datei ueberschreiben
  --from-date=YYYY-MM-DD  Nur Daten ab diesem Tag
  --to-date=YYYY-MM-DD    Nur Daten bis zu diesem Tag
  --vacation-year=YYYY    Jahr fuer emploee.holidays
  --report=PFAD        Basisname oder .txt/.json fuer den Bericht
  --no-backup          Keine automatische SQLite-Sicherung (nicht empfohlen)
  --inspect            Nur MySQL-Tabellen und Spalten untersuchen
  --dry-run            Vollstaendige Simulation, keine Datenbankaenderung
  --execute            Import wirklich in SQLite ausfuehren
  --help               Diese Hilfe

Ohne Modus wird automatisch --dry-run verwendet.
TXT;
}

function cliOption(array $options, string $name, mixed $default = null): mixed
{
    return array_key_exists($name, $options) ? $options[$name] : $default;
}

function validateIsoDate(?string $value, string $option): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }
    $value = trim($value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException($option . ' muss YYYY-MM-DD sein.');
    }
    return $value;
}

function connectMysql(array $config): PDO
{
    $drivers = PDO::getAvailableDrivers();
    if (!in_array('mysql', $drivers, true)) {
        throw new RuntimeException('PDO_MySQL ist nicht aktiviert. Aktive PDO-Treiber: ' . implode(', ', $drivers));
    }
    $host = trim((string)($config['host'] ?? ''));
    $database = trim((string)($config['database'] ?? ''));
    $username = (string)($config['username'] ?? '');
    $password = (string)($config['password'] ?? '');
    $port = (int)($config['port'] ?? 3306);
    $charset = trim((string)($config['charset'] ?? 'utf8mb4')) ?: 'utf8mb4';
    if ($host === '' || $database === '' || $username === '') {
        throw new RuntimeException('MySQL host, database und username muessen in tools/mysql-import.php gesetzt sein.');
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);
    $attributes = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => (int)($config['connect_timeout_seconds'] ?? 10),
    ];
    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
        $attributes[constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')] = true;
    }
    return new PDO($dsn, $username, $password, $attributes);
}

function connectSqlite(string $file): PDO
{
    $drivers = PDO::getAvailableDrivers();
    if (!in_array('sqlite', $drivers, true)) {
        throw new RuntimeException('PDO_SQLite ist nicht aktiviert. Aktive PDO-Treiber: ' . implode(', ', $drivers));
    }
    if (!is_file($file)) {
        throw new RuntimeException('Ziel-SQLite-Datei existiert nicht: ' . $file);
    }
    if (!is_readable($file)) {
        throw new RuntimeException('Ziel-SQLite-Datei ist nicht lesbar: ' . $file);
    }
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 15000');
    return $pdo;
}

function createSqliteBackup(PDO $pdo, string $targetFile): string
{
    $directory = dirname($targetFile);
    $base = pathinfo($targetFile, PATHINFO_FILENAME);
    $timestamp = gmdate('Ymd-His');
    $backup = $directory . DIRECTORY_SEPARATOR . $base . '.before-legacy-import-' . $timestamp . '.sqlite';
    $pdo->exec('VACUUM INTO ' . $pdo->quote($backup));
    if (!is_file($backup) || filesize($backup) === 0) {
        throw new RuntimeException('SQLite-Sicherung konnte nicht erstellt werden: ' . $backup);
    }
    return $backup;
}

function createBatch(PDO $pdo, array $config): int
{
    $reference = sprintf(
        '%s:%s/%s',
        (string)($config['mysql']['host'] ?? ''),
        (string)($config['mysql']['port'] ?? 3306),
        (string)($config['mysql']['database'] ?? '')
    );
    $statement = $pdo->prepare(
        "INSERT INTO import_batch(source_system, source_reference, status, started_at, summary)
         VALUES('legacy-mysql', ?, 'RUNNING', ?, '')"
    );
    $statement->execute([$reference, gmdate('Y-m-d H:i:s')]);
    return (int)$pdo->lastInsertId();
}

function finishBatch(PDO $pdo, int $batchId, string $status, LegacyImportReport $report): void
{
    $statement = $pdo->prepare('UPDATE import_batch SET status=?, finished_at=?, summary=? WHERE id=?');
    $summary = json_encode($report->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $statement->execute([$status, gmdate('Y-m-d H:i:s'), $summary ?: '{}', $batchId]);
}

function reportPaths(string|false|null $requested, string $directory): array
{
    if ($requested === false || $requested === null || trim((string)$requested) === '') {
        $base = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'legacy-import-' . gmdate('Ymd-His');
        return [$base . '.txt', $base . '.json'];
    }
    $path = (string)$requested;
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($extension === 'txt') {
        return [$path, substr($path, 0, -4) . '.json'];
    }
    if ($extension === 'json') {
        return [substr($path, 0, -5) . '.txt', $path];
    }
    return [$path . '.txt', $path . '.json'];
}

function writeReports(LegacyImportReport $report, string $textPath, string $jsonPath): void
{
    foreach ([dirname($textPath), dirname($jsonPath)] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Berichtsordner konnte nicht erstellt werden: ' . $directory);
        }
    }
    if (file_put_contents($textPath, $report->toText()) === false) {
        throw new RuntimeException('Textbericht konnte nicht geschrieben werden: ' . $textPath);
    }
    $json = json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($jsonPath, $json . PHP_EOL) === false) {
        throw new RuntimeException('JSON-Bericht konnte nicht geschrieben werden: ' . $jsonPath);
    }
}

$options = getopt('', [
    'config:', 'target:', 'from-date:', 'to-date:', 'vacation-year:', 'report:',
    'no-backup', 'inspect', 'dry-run', 'execute', 'help',
]);

if (isset($options['help'])) {
    echo usage();
    exit(0);
}

$modes = array_filter([
    'inspect' => isset($options['inspect']),
    'dry-run' => isset($options['dry-run']),
    'execute' => isset($options['execute']),
]);
if (count($modes) > 1) {
    fwrite(STDERR, "Nur einer der Modi --inspect, --dry-run oder --execute ist erlaubt.\n");
    exit(2);
}
$mode = array_key_first($modes) ?: 'dry-run';
$execute = $mode === 'execute';
$report = new LegacyImportReport($mode);
$batchId = null;
$target = null;
$exitCode = 0;

try {
    $configPath = (string)cliOption($options, 'config', __DIR__ . '/mysql-import.php');
    if (!is_file($configPath)) {
        throw new RuntimeException(
            'Konfiguration fehlt: ' . $configPath . PHP_EOL .
            'Kopiere tools/mysql-import.example.php nach tools/mysql-import.php und trage die MySQL-Zugangsdaten ein.'
        );
    }
    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('Die Import-Konfiguration muss ein PHP-Array zurueckgeben.');
    }

    $config['from_date'] = validateIsoDate(
        cliOption($options, 'from-date', $config['from_date'] ?? null),
        '--from-date'
    ) ?? '';
    $config['to_date'] = validateIsoDate(
        cliOption($options, 'to-date', $config['to_date'] ?? null),
        '--to-date'
    ) ?? '';
    if ($config['from_date'] !== '' && $config['to_date'] !== '' && $config['from_date'] > $config['to_date']) {
        throw new InvalidArgumentException('--from-date darf nicht nach --to-date liegen.');
    }
    if (isset($options['vacation-year'])) {
        $year = (int)$options['vacation-year'];
        if ($year < 1970 || $year > 2200) {
            throw new InvalidArgumentException('--vacation-year muss zwischen 1970 und 2200 liegen.');
        }
        $config['vacation_entitlement_year'] = $year;
    }

    $targetFromCli = isset($options['target']);
    $targetFile = (string)cliOption(
        $options,
        'target',
        $config['target_sqlite'] ?? (__DIR__ . '/../data/stempeluhr.sqlite')
    );
    if (!str_starts_with($targetFile, DIRECTORY_SEPARATOR) && preg_match('/^[A-Za-z]:[\\\\\/]/', $targetFile) !== 1) {
        $baseDirectory = $targetFromCli ? (getcwd() ?: '.') : dirname($configPath);
        $targetFile = $baseDirectory . DIRECTORY_SEPARATOR . $targetFile;
    }
    $targetFile = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $targetFile);

    echo "Modus:              {$mode}\n";
    echo 'MySQL-Datenbank:    ' . (string)($config['mysql']['database'] ?? '') . '@' . (string)($config['mysql']['host'] ?? '') . "\n";
    echo "Ziel-SQLite:        {$targetFile}\n";
    echo 'Datumsbereich:      ' . ($config['from_date'] ?: 'offen') . ' bis ' . ($config['to_date'] ?: 'offen') . "\n";
    if ($execute) {
        echo "Schreibmodus:        JA, nur SQLite\n";
    } else {
        echo "Schreibmodus:        NEIN\n";
    }

    $source = connectMysql((array)($config['mysql'] ?? []));
    $target = connectSqlite($targetFile);

    if ($mode === 'inspect') {
        $importer = new LegacyMysqlImporter($source, $target, $config, $report, false);
        $importer->inspectOnly();
    } else {
        if ($execute && !isset($options['no-backup'])) {
            $backup = createSqliteBackup($target, $targetFile);
            $report->info('Automatische SQLite-Sicherung: ' . $backup);
            echo "SQLite-Sicherung:   {$backup}\n";
        }

        if ($execute) {
            if (!is_writable($targetFile)) {
                throw new RuntimeException('Ziel-SQLite-Datei ist nicht beschreibbar: ' . $targetFile);
            }
            $batchId = createBatch($target, $config);
            $target->beginTransaction();
        }

        $importer = new LegacyMysqlImporter($source, $target, $config, $report, $execute);
        $importer->run();

        if ($execute && $target->inTransaction()) {
            $target->commit();
        }
    }
} catch (Throwable $e) {
    if ($target instanceof PDO && $target->inTransaction()) {
        $target->rollBack();
    }
    $report->error($e->getMessage());
    $exitCode = 1;
}

$report->finish();
if ($batchId !== null && $target instanceof PDO) {
    try {
        finishBatch($target, $batchId, $exitCode === 0 && !$report->hasErrors() ? 'SUCCESS' : 'FAILED', $report);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Import-Batch konnte nicht abgeschlossen werden: ' . $e->getMessage() . PHP_EOL);
        $exitCode = 1;
    }
}

try {
    $defaultReportDirectory = __DIR__ . '/../data/import-reports';
    [$textReport, $jsonReport] = reportPaths(cliOption($options, 'report'), $defaultReportDirectory);
    writeReports($report, $textReport, $jsonReport);
    echo "Textbericht:         {$textReport}\n";
    echo "JSON-Bericht:        {$jsonReport}\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Bericht konnte nicht geschrieben werden: ' . $e->getMessage() . PHP_EOL);
    $exitCode = 1;
}

echo PHP_EOL . $report->toText();
if ($report->hasErrors()) {
    $exitCode = 1;
}
exit($exitCode);
