<?php
declare(strict_types=1);

$path = __DIR__ . '/../app/LegacyMysqlImporter.php';
$code = file_get_contents($path);
if ($code === false) {
    fwrite(STDERR, "Importer konnte nicht gelesen werden.\n");
    exit(1);
}

foreach (['$this->source->prepare(', '$this->source->query('] as $forbiddenCall) {
    if (str_contains($code, $forbiddenCall)) {
        fwrite(STDERR, "Nicht erlaubter direkter Source-Aufruf gefunden: {$forbiddenCall}\n");
        exit(1);
    }
}

if (!preg_match('/final class LegacySourceSchema(.*?)final class LegacyMysqlImporter/s', $code, $match)) {
    fwrite(STDERR, "LegacySourceSchema konnte nicht isoliert werden.\n");
    exit(1);
}
$sourceSchemaCode = $match[1];
foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'ALTER ', 'DROP ', 'TRUNCATE ', 'REPLACE '] as $keyword) {
    if (stripos($sourceSchemaCode, $keyword) !== false) {
        fwrite(STDERR, "Schreibendes SQL im Source-Schema gefunden: {$keyword}\n");
        exit(1);
    }
}

$allowedExecFragments = [
    "SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ",
    "START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY",
    "ROLLBACK",
];
preg_match_all('/\\$this->source->exec\\(([^;]+)\\);/', $code, $matches);
foreach ($matches[1] as $expression) {
    $allowed = false;
    foreach ($allowedExecFragments as $fragment) {
        if (str_contains($expression, $fragment)) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        fwrite(STDERR, "Nicht freigegebener Source-exec-Aufruf: {$expression}\n");
        exit(1);
    }
}

echo "LegacyImporterSourceSafetyTest: OK\n";
