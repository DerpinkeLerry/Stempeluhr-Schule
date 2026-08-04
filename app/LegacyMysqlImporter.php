<?php
declare(strict_types=1);

final class LegacyImportReport
{
    private string $mode;
    private string $startedAt;
    private ?string $finishedAt = null;
    private array $counters = [];
    private array $warnings = [];
    private array $errors = [];
    private array $info = [];
    private array $samples = [];
    private array $sourceSchema = [];

    public function __construct(string $mode)
    {
        $this->mode = $mode;
        $this->startedAt = gmdate('c');
    }

    public function increment(string $key, int $amount = 1): void
    {
        $this->counters[$key] = ($this->counters[$key] ?? 0) + $amount;
    }

    public function setCounter(string $key, int $value): void
    {
        $this->counters[$key] = $value;
    }

    public function warning(string $message): void
    {
        if (count($this->warnings) < 5000) {
            $this->warnings[] = $message;
        }
        $this->increment('warnings');
    }

    public function error(string $message): void
    {
        if (count($this->errors) < 5000) {
            $this->errors[] = $message;
        }
        $this->increment('errors');
    }

    public function info(string $message): void
    {
        $this->info[] = $message;
    }

    public function sample(string $category, array $sample, int $limit = 12): void
    {
        $this->samples[$category] ??= [];
        if (count($this->samples[$category]) < $limit) {
            $this->samples[$category][] = $sample;
        }
    }

    public function setSourceSchema(array $schema): void
    {
        $this->sourceSchema = $schema;
    }

    public function hasErrors(): bool
    {
        return ($this->counters['errors'] ?? 0) > 0;
    }

    public function finish(): void
    {
        $this->finishedAt = gmdate('c');
        ksort($this->counters);
    }

    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'counters' => $this->counters,
            'info' => $this->info,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'samples' => $this->samples,
            'source_schema' => $this->sourceSchema,
        ];
    }

    public function toText(): string
    {
        $lines = [];
        $lines[] = 'Legacy-MySQL-Importbericht';
        $lines[] = str_repeat('=', 30);
        $lines[] = 'Modus:       ' . $this->mode;
        $lines[] = 'Gestartet:   ' . $this->startedAt;
        $lines[] = 'Beendet:     ' . ($this->finishedAt ?? '-');
        $lines[] = '';
        $lines[] = 'Zaehler';
        $lines[] = '-------';
        foreach ($this->counters as $key => $value) {
            $lines[] = sprintf('%-42s %d', $key, $value);
        }

        if ($this->info) {
            $lines[] = '';
            $lines[] = 'Hinweise';
            $lines[] = '--------';
            foreach ($this->info as $message) {
                $lines[] = '- ' . $message;
            }
        }

        if ($this->warnings) {
            $lines[] = '';
            $lines[] = 'Warnungen';
            $lines[] = '---------';
            foreach ($this->warnings as $message) {
                $lines[] = '- ' . $message;
            }
        }

        if ($this->errors) {
            $lines[] = '';
            $lines[] = 'Fehler';
            $lines[] = '------';
            foreach ($this->errors as $message) {
                $lines[] = '- ' . $message;
            }
        }

        if ($this->samples) {
            $lines[] = '';
            $lines[] = 'Stichproben';
            $lines[] = '-----------';
            foreach ($this->samples as $category => $samples) {
                $lines[] = '[' . $category . ']';
                foreach ($samples as $sample) {
                    $lines[] = json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
        }

        if ($this->sourceSchema) {
            $lines[] = '';
            $lines[] = 'Erkannte MySQL-Struktur';
            $lines[] = '------------------------';
            foreach ($this->sourceSchema as $table => $columns) {
                $lines[] = $table . ': ' . implode(', ', $columns);
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}

final class LegacyValueConverter
{
    private DateTimeZone $sourceTimezone;
    private DateTimeZone $utcTimezone;
    private int $timestampAdjustmentSeconds;

    public function __construct(string $sourceTimezone, int $timestampAdjustmentSeconds = 0)
    {
        $this->sourceTimezone = new DateTimeZone($sourceTimezone);
        $this->utcTimezone = new DateTimeZone('UTC');
        $this->timestampAdjustmentSeconds = $timestampAdjustmentSeconds;
    }

    public function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null) {
            return false;
        }
        if (is_string($value) && strlen($value) === 1 && (ord($value) === 0 || ord($value) === 1)) {
            return ord($value) === 1;
        }
        if (is_numeric($value)) {
            return (float)$value !== 0.0;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'ja', 'on', 'wahr'], true);
    }

    public function number(mixed $value, float $default = 0.0): float
    {
        if ($value === null || trim((string)$value) === '') {
            return $default;
        }
        $normalized = str_replace(',', '.', trim((string)$value));
        return is_numeric($normalized) ? (float)$normalized : $default;
    }

    public function date(mixed $value): ?string
    {
        if ($this->isEmptyTimeValue($value)) {
            return null;
        }

        if (is_numeric($value)) {
            $number = (float)$value;
            if ($number >= 19000101 && $number <= 22001231 && floor($number) === $number) {
                $date = DateTimeImmutable::createFromFormat('!Ymd', (string)(int)$number, $this->sourceTimezone);
                return $date ? $date->format('Y-m-d') : null;
            }
            $timestamp = $this->normalizeEpoch($number);
            return (new DateTimeImmutable('@' . $timestamp))
                ->setTimezone($this->sourceTimezone)
                ->format('Y-m-d');
        }

        $text = trim((string)$value);
        foreach (['!Y-m-d', '!d.m.Y', '!Y/m/d', '!d/m/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $text, $this->sourceTimezone);
            if ($date instanceof DateTimeImmutable) {
                return $date->format('Y-m-d');
            }
        }

        try {
            return (new DateTimeImmutable($text, $this->sourceTimezone))->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    public function utcDateTime(mixed $value, ?string $contextDate = null): ?string
    {
        if ($this->isEmptyTimeValue($value)) {
            return null;
        }

        if (is_numeric($value)) {
            $number = (float)$value;
            if (abs($number) < 172800 && $contextDate !== null) {
                $seconds = (int)round($number);
                $local = new DateTimeImmutable($contextDate . ' 00:00:00', $this->sourceTimezone);
                return $local->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds')
                    ->setTimezone($this->utcTimezone)
                    ->format('Y-m-d H:i:s');
            }
            $timestamp = $this->normalizeEpoch($number);
            return gmdate('Y-m-d H:i:s', $timestamp);
        }

        $text = trim((string)$value);
        if ($contextDate !== null && preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $text) === 1) {
            $parts = explode(':', $text);
            $text = $contextDate . ' ' . sprintf('%02d:%02d:%02d', (int)$parts[0], (int)$parts[1], (int)($parts[2] ?? 0));
        }

        try {
            $date = new DateTimeImmutable($text, $this->sourceTimezone);
            return $date->setTimezone($this->utcTimezone)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    public function localTime(?string $utcDateTime): ?string
    {
        if ($utcDateTime === null || $utcDateTime === '') {
            return null;
        }
        return (new DateTimeImmutable($utcDateTime, $this->utcTimezone))
            ->setTimezone($this->sourceTimezone)
            ->format('H:i:s');
    }

    public function localDate(?string $utcDateTime): ?string
    {
        if ($utcDateTime === null || $utcDateTime === '') {
            return null;
        }
        return (new DateTimeImmutable($utcDateTime, $this->utcTimezone))
            ->setTimezone($this->sourceTimezone)
            ->format('Y-m-d');
    }

    public function localDayUtcBounds(string $date): array
    {
        $start = new DateTimeImmutable($date . ' 00:00:00', $this->sourceTimezone);
        $end = $start->modify('+1 day');
        return [
            $start->setTimezone($this->utcTimezone)->format('Y-m-d H:i:s'),
            $end->setTimezone($this->utcTimezone)->format('Y-m-d H:i:s'),
        ];
    }

    public function localDateTimeToUtc(string $date, string $time): string
    {
        $local = new DateTimeImmutable($date . ' ' . $time, $this->sourceTimezone);
        return $local->setTimezone($this->utcTimezone)->format('Y-m-d H:i:s');
    }

    private function normalizeEpoch(float $value): int
    {
        if (abs($value) >= 100000000000.0) {
            $value /= 1000.0;
        }
        return (int)round($value) + $this->timestampAdjustmentSeconds;
    }

    private function isEmptyTimeValue(mixed $value): bool
    {
        if ($value === null || $value === false) {
            return true;
        }
        if (is_string($value)) {
            $text = trim($value);
            return $text === '' || $text === '0' || $text === '0000-00-00' || $text === '0000-00-00 00:00:00';
        }
        return is_numeric($value) && (float)$value === 0.0;
    }
}

final class LegacySourceSchema
{
    private PDO $pdo;
    private array $tables = [];
    private array $columns = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $rows = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as $row) {
            if (!isset($row[0])) {
                continue;
            }
            $name = (string)$row[0];
            $this->tables[strtolower($name)] = $name;
        }
    }

    public function tables(): array
    {
        return array_values($this->tables);
    }

    public function describe(): array
    {
        $result = [];
        foreach ($this->tables() as $table) {
            $result[$table] = array_values($this->columns($table));
        }
        ksort($result);
        return $result;
    }

    public function resolveTable(?string $configured, array $candidates, bool $required): ?string
    {
        if ($configured !== null && trim($configured) !== '') {
            $key = strtolower(trim($configured));
            if (isset($this->tables[$key])) {
                return $this->tables[$key];
            }
            if ($required) {
                throw new RuntimeException('Konfigurierte MySQL-Tabelle fehlt: ' . $configured);
            }
            return null;
        }

        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (isset($this->tables[$key])) {
                return $this->tables[$key];
            }
        }

        if ($required) {
            throw new RuntimeException('Keine passende MySQL-Tabelle gefunden. Erwartet: ' . implode(', ', $candidates));
        }
        return null;
    }

    public function columns(string $table): array
    {
        $tableKey = strtolower($table);
        if (isset($this->columns[$tableKey])) {
            return $this->columns[$tableKey];
        }
        $columns = [];
        $statement = $this->pdo->query('SHOW COLUMNS FROM ' . self::quoteIdentifier($table));
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string)$row['Field'];
            $columns[strtolower($name)] = $name;
        }
        $this->columns[$tableKey] = $columns;
        return $columns;
    }

    public function resolveColumns(string $table, array $fieldCandidates, array $configured, array $required): array
    {
        $columns = $this->columns($table);
        $result = [];
        foreach ($fieldCandidates as $canonical => $candidates) {
            $override = $configured[$canonical] ?? null;
            if (is_string($override) && trim($override) !== '') {
                $key = strtolower(trim($override));
                if (!isset($columns[$key])) {
                    throw new RuntimeException(sprintf('Spalte %s.%s wurde konfiguriert, existiert aber nicht.', $table, $override));
                }
                $result[$canonical] = $columns[$key];
                continue;
            }

            $result[$canonical] = null;
            foreach ($candidates as $candidate) {
                $key = strtolower($candidate);
                if (isset($columns[$key])) {
                    $result[$canonical] = $columns[$key];
                    break;
                }
            }

            if (in_array($canonical, $required, true) && $result[$canonical] === null) {
                throw new RuntimeException(sprintf(
                    'Pflichtspalte fuer %s.%s fehlt. Gefundene Spalten: %s',
                    $table,
                    $canonical,
                    implode(', ', array_values($columns))
                ));
            }
        }
        return $result;
    }

    public function count(string $table): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM ' . self::quoteIdentifier($table))->fetchColumn();
    }

    public function select(string $table, array $mapping, array $order = []): PDOStatement
    {
        $select = [];
        foreach ($mapping as $canonical => $actual) {
            $select[] = $actual === null
                ? 'NULL AS ' . self::quoteIdentifier($canonical)
                : self::quoteIdentifier($actual) . ' AS ' . self::quoteIdentifier($canonical);
        }
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM ' . self::quoteIdentifier($table);
        $orderParts = [];
        foreach ($order as $canonical) {
            if (($mapping[$canonical] ?? null) !== null) {
                $orderParts[] = self::quoteIdentifier((string)$mapping[$canonical]);
            }
        }
        if ($orderParts) {
            $sql .= ' ORDER BY ' . implode(', ', $orderParts);
        }
        return $this->pdo->query($sql);
    }

    public static function quoteIdentifier(string $identifier): string
    {
        if ($identifier === '' || str_contains($identifier, "\0")) {
            throw new InvalidArgumentException('Ungueltiger SQL-Bezeichner');
        }
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}

final class LegacyMysqlImporter
{
    private PDO $source;
    private PDO $target;
    private array $config;
    private LegacyImportReport $report;
    private LegacyValueConverter $converter;
    private LegacySourceSchema $schema;
    private bool $execute;
    private array $tables = [];
    private array $columns = [];
    private array $employeeMap = [];
    private array $sourceEmployeeRows = [];
    private array $sessionMap = [];
    private array $publicHolidayDays = [];
    private int $virtualId = -1;
    private bool $sourceTransactionStarted = false;

    private const TABLE_CANDIDATES = [
        'employees' => ['emploee', 'employee', 'employees', 'mitarbeiter'],
        'worktime' => ['worktime', 'work_time', 'arbeitszeit', 'arbeitszeiten'],
        'breaks' => ['break', 'breaks', 'breaktime', 'pause', 'pausen'],
        'public_holidays' => ['public_holiday', 'publicholiday', 'public_holidays', 'feiertage'],
        'overtime' => ['overtime', 'overtimes', 'overtime_event', 'ueberstunden', 'sondertage'],
    ];

    private const COLUMN_CANDIDATES = [
        'employees' => [
            'id' => ['id', 'employee_id', 'emploee_id'],
            'persid' => ['persid', 'personalid', 'personal_id', 'personnel_number', 'personalnummer'],
            'name' => ['name', 'employee_name', 'mitarbeiter'],
            'holidays' => ['holidays', 'holiday_days', 'vacation_days', 'urlaubstage', 'urlaub'],
            'weekhours' => ['weekhours', 'weekly_hours', 'week_hours', 'wochenstunden'],
            'unemployed' => ['unemployed', 'inactive', 'dismissed', 'ausgeschieden'],
            'department' => ['department', 'abteilung'],
            'phone' => ['phone', 'telephone', 'telefon'],
            'azubi' => ['azubi', 'trainee', 'is_trainee'],
            'specialtime' => ['specialtime', 'special_time', 'sonderzeit'],
        ],
        'worktime' => [
            'id' => ['id', 'worktime_id', 'work_time_id'],
            'persid' => ['persid', 'personalid', 'personal_id', 'personnel_number', 'personalnummer'],
            'date' => ['date', 'day', 'work_date', 'datum'],
            'work_start' => ['work_start', 'started_at', 'start_time', 'start'],
            'work_end' => ['work_end', 'ended_at', 'end_time', 'end'],
            'holiday' => ['holiday', 'vacation', 'urlaub'],
            'ill' => ['ill', 'sick', 'krank'],
            'other' => ['other', 'sonstige', 'feiertag'],
            'halfholiday' => ['halfholiday', 'half_holiday', 'halfurlaub'],
            'school' => ['school', 'schule'],
        ],
        'breaks' => [
            'id' => ['id', 'break_id', 'pause_id'],
            'persid' => ['persid', 'personalid', 'personal_id', 'personnel_number', 'personalnummer'],
            'date' => ['date', 'day', 'break_date', 'datum'],
            'break_start' => ['break_start', 'started_at', 'start_time', 'start'],
            'break_end' => ['break_end', 'ended_at', 'end_time', 'end'],
        ],
        'public_holidays' => [
            'id' => ['id', 'holiday_id', 'feiertag_id'],
            'date' => ['date', 'day', 'holiday_date', 'datum'],
            'name' => ['name', 'holiday_name', 'bezeichnung'],
        ],
        'overtime' => [
            'id' => ['id', 'overtime_id'],
            'date' => ['date', 'day', 'datum'],
            'note' => ['note', 'name', 'description', 'reason', 'bezeichnung'],
            'credit' => ['credit_minutes', 'minutes', 'overtime', 'time', 'value', 'gutschrift'],
        ],
    ];

    public function __construct(PDO $source, PDO $target, array $config, LegacyImportReport $report, bool $execute)
    {
        $this->source = $source;
        $this->target = $target;
        $this->config = $config;
        $this->report = $report;
        $this->execute = $execute;
        $this->converter = new LegacyValueConverter(
            (string)$this->get('source_timezone', 'Europe/Berlin'),
            (int)$this->get('legacy_timestamp_adjustment_seconds', 0)
        );
        $this->schema = new LegacySourceSchema($source);
    }

    public function inspectOnly(): void
    {
        $this->report->setSourceSchema($this->schema->describe());
        foreach ($this->schema->tables() as $table) {
            try {
                $this->report->setCounter('source_table.' . $table, $this->schema->count($table));
            } catch (Throwable $e) {
                $this->report->warning('Zeilenanzahl fuer Tabelle ' . $table . ' konnte nicht gelesen werden: ' . $e->getMessage());
            }
        }
        $this->resolveSourceStructure(false);
        $this->reportUnhandledTables();
    }

    public function run(): void
    {
        $this->validateTargetSchema();
        $this->report->setSourceSchema($this->schema->describe());
        $this->resolveSourceStructure(true);
        $this->reportUnhandledTables();
        $this->startReadOnlySnapshot();

        try {
            $this->importPublicHolidays();
            $this->importEmployees();
            if ($this->report->hasErrors()) {
                throw new RuntimeException('Vorpruefung der Mitarbeiter hat Fehler ergeben. Import wird nicht fortgesetzt.');
            }
            $this->importWorktime();
            $this->importBreaks();
            $this->importOvertime();
            if ($this->report->hasErrors()) {
                throw new RuntimeException('Der Importbericht enthaelt Fehler. Die SQLite-Transaktion wird verworfen.');
            }
            if ($this->execute) {
                $this->validateTargetIntegrity();
            }
        } finally {
            $this->endReadOnlySnapshot();
        }
    }

    private function resolveSourceStructure(bool $requireCore): void
    {
        $configuredTables = (array)$this->get('tables', []);
        foreach (self::TABLE_CANDIDATES as $logical => $candidates) {
            $required = $requireCore && in_array($logical, ['employees', 'worktime'], true);
            $this->tables[$logical] = $this->schema->resolveTable(
                isset($configuredTables[$logical]) ? (string)$configuredTables[$logical] : null,
                $candidates,
                $required
            );
        }

        $configuredColumns = (array)$this->get('columns', []);
        $requiredColumns = [
            'employees' => ['persid', 'name'],
            'worktime' => ['persid', 'date'],
            'breaks' => ['persid', 'date', 'break_start'],
            'public_holidays' => ['date', 'name'],
            'overtime' => ['date'],
        ];
        foreach ($this->tables as $logical => $table) {
            if ($table === null) {
                continue;
            }
            $this->columns[$logical] = $this->schema->resolveColumns(
                $table,
                self::COLUMN_CANDIDATES[$logical],
                (array)($configuredColumns[$logical] ?? []),
                $requiredColumns[$logical]
            );
            $this->report->setCounter('source.' . $logical, $this->schema->count($table));
        }
    }

    private function reportUnhandledTables(): void
    {
        $handled = array_filter($this->tables, static fn(mixed $value): bool => is_string($value) && $value !== '');
        $handledLower = array_map('strtolower', array_values($handled));
        foreach ($this->schema->tables() as $table) {
            if (!in_array(strtolower($table), $handledLower, true)) {
                $this->report->warning('Nicht automatisch zugeordnete MySQL-Tabelle: ' . $table . '. Sie wird nicht veraendert und nicht importiert.');
            }
        }
    }

    private function startReadOnlySnapshot(): void
    {
        try {
            $this->source->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->source->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
            $this->sourceTransactionStarted = true;
            $this->report->info('MySQL wurde als konsistenter READ-ONLY-Snapshot geoeffnet.');
        } catch (Throwable $e) {
            $this->report->warning('MySQL READ-ONLY-Snapshot konnte nicht explizit gestartet werden: ' . $e->getMessage() . '. Verwende unbedingt einen Benutzer mit reinen SELECT-Rechten.');
        }
    }

    private function endReadOnlySnapshot(): void
    {
        if (!$this->sourceTransactionStarted) {
            return;
        }
        try {
            $this->source->exec('ROLLBACK');
        } catch (Throwable) {
        }
        $this->sourceTransactionStarted = false;
    }

    private function importEmployees(): void
    {
        if (!$this->enabled('employees') || $this->tables['employees'] === null) {
            return;
        }

        $table = $this->tables['employees'];
        $mapping = $this->columns['employees'];
        $statement = $this->schema->select($table, $mapping, ['persid', 'id']);
        $explicitMap = (array)$this->get('employee_target_map', []);
        $skipPersonnel = array_map('strval', (array)$this->get('skip_source_personnel_numbers', []));

        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $this->report->increment('employees.read');
            $personnelNumber = trim((string)($row['persid'] ?? ''));
            if ($personnelNumber === '' || $personnelNumber === '0') {
                $this->report->error('Mitarbeiter ohne gueltige Personalnummer wurde gefunden. Quelldatensatz: ' . json_encode($row, JSON_UNESCAPED_UNICODE));
                continue;
            }
            if (in_array($personnelNumber, $skipPersonnel, true)) {
                $this->report->increment('employees.skipped_by_config');
                continue;
            }

            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                $this->report->error('Mitarbeiter ' . $personnelNumber . ' hat keinen Namen.');
                continue;
            }

            $legacyId = $this->legacyId('employee', $row['id'] ?? null, ['persid' => $personnelNumber, 'name' => $name]);
            $target = null;
            if (isset($explicitMap[$personnelNumber])) {
                $target = $this->findTargetEmployeeByPersonnelNumber((string)$explicitMap[$personnelNumber]);
                if ($target === null) {
                    $this->report->error('Explizites Mitarbeiter-Mapping fuer ' . $personnelNumber . ' verweist auf eine unbekannte Ziel-Personalnummer: ' . $explicitMap[$personnelNumber]);
                    continue;
                }
            }
            $target ??= $this->findTargetEmployeeByLegacyId($legacyId);
            $target ??= $this->findTargetEmployeeByPersonnelNumber($personnelNumber);

            if ($target !== null && $target['legacy_employee_id'] !== null && (int)$target['legacy_employee_id'] !== $legacyId) {
                $this->report->error(sprintf(
                    'Personalnummer %s ist bereits mit einer anderen Legacy-ID verbunden (%s statt %s).',
                    $personnelNumber,
                    $target['legacy_employee_id'],
                    $legacyId
                ));
                continue;
            }

            if ($target !== null && $target['legacy_employee_id'] === null && !$this->samePersonName((string)$target['name'], $name)) {
                $policy = (string)$this->get('employee_name_conflict_policy', 'fail');
                $message = sprintf(
                    'Namenskonflikt fuer Personalnummer %s: Ziel "%s", Quelle "%s".',
                    $personnelNumber,
                    $target['name'],
                    $name
                );
                if ($policy === 'fail') {
                    $this->report->error($message . ' Loesche/korigiere den Demo-Datensatz oder trage employee_target_map ein.');
                    continue;
                }
                if ($policy === 'skip') {
                    $this->report->warning($message . ' Datensatz wurde uebersprungen.');
                    $this->report->increment('employees.skipped_conflict');
                    continue;
                }
                $this->report->warning($message . ' Quelle wird gemaess Konfiguration verknuepft.');
            }

            $weeklyHours = max(0.0, min(168.0, $this->converter->number($row['weekhours'] ?? null, 38.0)));
            $holidayDays = max(0.0, $this->converter->number($row['holidays'] ?? null, 0.0));
            $active = $this->converter->bool($row['unemployed'] ?? false) ? 0 : 1;
            $employeeData = [
                'legacy_id' => $legacyId,
                'personnel_number' => $personnelNumber,
                'name' => $name,
                'department' => trim((string)($row['department'] ?? '')),
                'phone' => trim((string)($row['phone'] ?? '')),
                'weekly_hours' => $weeklyHours,
                'is_trainee' => $this->converter->bool($row['azubi'] ?? false) ? 1 : 0,
                'special_time' => $this->converter->bool($row['specialtime'] ?? false) ? 1 : 0,
                'active' => $active,
                'holiday_days' => $holidayDays,
            ];

            if ($target === null) {
                $targetId = $this->execute ? $this->insertEmployee($employeeData) : $this->virtualId--;
                $this->report->increment('employees.inserted');
            } else {
                $targetId = (int)$target['id'];
                if ($this->execute) {
                    $this->updateEmployee($targetId, $employeeData);
                }
                $this->report->increment('employees.merged');
            }

            $this->employeeMap[$personnelNumber] = $targetId;
            $this->sourceEmployeeRows[$personnelNumber] = $employeeData;
            $this->report->sample('employees', [
                'personnel_number' => $personnelNumber,
                'name' => $name,
                'target_id' => $targetId,
                'legacy_id' => $legacyId,
                'weekly_hours' => $weeklyHours,
                'active' => $active,
            ]);

            $this->importEmployeeSchedule($targetId, $employeeData);
            $this->importVacationAccount($targetId, $employeeData);
        }
    }

    private function insertEmployee(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $statement = $this->target->prepare(
            'INSERT INTO employee(
                personnel_number, legacy_employee_id, name, email, password_hash, role, timezone,
                holiday_region, department, phone, weekly_hours, is_trainee, special_time,
                active, login_enabled, must_change_password, created_at, updated_at
             ) VALUES(?,?,?,NULL,\'\',\'employee\',?,?,?,?,?,?,?, ?,0,0,?,?)'
        );
        $statement->execute([
            $data['personnel_number'],
            $data['legacy_id'],
            $data['name'],
            (string)$this->get('source_timezone', 'Europe/Berlin'),
            (string)$this->get('holiday_region', 'DE-BY-KF'),
            $data['department'],
            $data['phone'],
            $data['weekly_hours'],
            $data['is_trainee'],
            $data['special_time'],
            $data['active'],
            $now,
            $now,
        ]);
        return (int)$this->target->lastInsertId();
    }

    private function updateEmployee(int $targetId, array $data): void
    {
        $policy = (string)$this->get('employee_profile_merge_policy', 'legacy-wins');
        $now = gmdate('Y-m-d H:i:s');
        if ($policy === 'preserve-target') {
            $statement = $this->target->prepare(
                'UPDATE employee SET legacy_employee_id=COALESCE(legacy_employee_id, ?), updated_at=? WHERE id=?'
            );
            $statement->execute([$data['legacy_id'], $now, $targetId]);
            return;
        }

        $statement = $this->target->prepare(
            'UPDATE employee SET
                legacy_employee_id=COALESCE(legacy_employee_id, ?),
                name=?, department=?, phone=?, weekly_hours=?, is_trainee=?, special_time=?, active=?,
                holiday_region=CASE WHEN trim(holiday_region)=\'\' THEN ? ELSE holiday_region END,
                updated_at=?
             WHERE id=?'
        );
        $statement->execute([
            $data['legacy_id'],
            $data['name'],
            $data['department'],
            $data['phone'],
            $data['weekly_hours'],
            $data['is_trainee'],
            $data['special_time'],
            $data['active'],
            (string)$this->get('holiday_region', 'DE-BY-KF'),
            $now,
            $targetId,
        ]);
    }

    private function importEmployeeSchedule(int $employeeId, array $data): void
    {
        if (!$this->enabled('schedules')) {
            return;
        }
        if ($employeeId > 0) {
            $statement = $this->target->prepare('SELECT COUNT(*) FROM employee_schedule WHERE employee_id=?');
            $statement->execute([$employeeId]);
            if ((int)$statement->fetchColumn() > 0) {
                $this->report->increment('schedules.preserved_existing');
                return;
            }
        }

        $minutes = $this->defaultScheduleMinutes((float)$data['weekly_hours']);
        $this->report->increment('schedules.employees_created');
        $this->report->increment('schedules.rows_inserted', 7);
        if (!$this->execute) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $validFrom = (string)$this->get('schedule_valid_from', '1970-01-01');
        $statement = $this->target->prepare(
            'INSERT INTO employee_schedule(
                employee_id, valid_from, valid_to, weekday, target_minutes, planned_start, planned_end,
                source, created_at, updated_at
             ) VALUES(?,?,NULL,?,?,?,?,\'legacy-mysql\',?,?)'
        );
        foreach ($minutes as $weekday => $targetMinutes) {
            $start = $targetMinutes > 0 ? '08:00' : '';
            $end = $targetMinutes > 0 ? $this->minutesToTime(480 + $targetMinutes) : '';
            $statement->execute([$employeeId, $validFrom, $weekday, $targetMinutes, $start, $end, $now, $now]);
        }
    }

    private function importVacationAccount(int $employeeId, array $data): void
    {
        if (!$this->enabled('vacation_accounts')) {
            return;
        }
        $year = (int)$this->get('vacation_entitlement_year', (int)date('Y'));
        $days = (float)$data['holiday_days'];
        $existing = null;
        if ($employeeId > 0) {
            $statement = $this->target->prepare('SELECT * FROM vacation_account WHERE employee_id=? AND year=?');
            $statement->execute([$employeeId, $year]);
            $existing = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($existing === null) {
            $this->report->increment('vacation_accounts.inserted');
            if ($this->execute) {
                $now = gmdate('Y-m-d H:i:s');
                $statement = $this->target->prepare(
                    'INSERT INTO vacation_account(
                        employee_id, year, entitlement_days, carryover_days, adjustment_days, note,
                        source, legacy_entitlement_days, created_at, updated_at
                     ) VALUES(?,?,?,0,0,?,\'legacy-mysql\',?,?,?)'
                );
                $statement->execute([
                    $employeeId,
                    $year,
                    $days,
                    'Urlaubsanspruch aus emploee.holidays; Altsystem war nicht jahresbezogen.',
                    $days,
                    $now,
                    $now,
                ]);
            }
            return;
        }

        $this->report->increment('vacation_accounts.merged');
        if (!$this->execute) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $fillEmpty = (string)$this->get('vacation_account_policy', 'fill-empty') === 'fill-empty';
        $entitlement = $fillEmpty && (float)$existing['entitlement_days'] !== 0.0
            ? (float)$existing['entitlement_days']
            : $days;
        $statement = $this->target->prepare(
            'UPDATE vacation_account SET entitlement_days=?, legacy_entitlement_days=?, updated_at=? WHERE id=?'
        );
        $statement->execute([$entitlement, $days, $now, $existing['id']]);
    }

    private function importPublicHolidays(): void
    {
        $region = (string)$this->get('holiday_region', 'DE-BY-KF');
        $targetDays = $this->target->prepare('SELECT day FROM public_holiday WHERE region=?');
        $targetDays->execute([$region]);
        foreach ($targetDays->fetchAll(PDO::FETCH_COLUMN) as $targetDay) {
            $this->publicHolidayDays[(string)$targetDay] = true;
        }

        if ($this->tables['public_holidays'] === null) {
            $this->report->warning('Keine alte Feiertagstabelle gefunden. Fuer worktime.other wird ersatzweise der Feiertagskalender der neuen Datenbank verwendet.');
            return;
        }

        $table = $this->tables['public_holidays'];
        $mapping = $this->columns['public_holidays'];
        $statement = $this->schema->select($table, $mapping, ['date', 'id']);

        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $this->report->increment('public_holidays.read');
            $date = $this->converter->date($row['date'] ?? null);
            $name = trim((string)($row['name'] ?? ''));
            if ($date === null || $name === '') {
                $this->report->warning('Ungueltiger Feiertag wurde uebersprungen: ' . json_encode($row, JSON_UNESCAPED_UNICODE));
                $this->report->increment('public_holidays.skipped_invalid');
                continue;
            }
            $this->publicHolidayDays[$date] = true;
            if (!$this->enabled('public_holidays')) {
                continue;
            }
            if (!$this->dateAllowed($date)) {
                $this->report->increment('public_holidays.filtered');
                continue;
            }

            $legacyId = $this->legacyId('public_holiday', $row['id'] ?? null, ['date' => $date, 'name' => $name]);
            $existing = $this->findPublicHoliday($legacyId, $region, $date, $name);
            if ($existing !== null) {
                $this->report->increment('public_holidays.merged');
                if ($this->execute && $existing['legacy_public_holiday_id'] === null) {
                    $update = $this->target->prepare('UPDATE public_holiday SET legacy_public_holiday_id=?, updated_at=? WHERE id=?');
                    $update->execute([$legacyId, gmdate('Y-m-d H:i:s'), $existing['id']]);
                }
                if ((string)$existing['name'] !== $name) {
                    $this->report->warning(sprintf('Feiertagsname unterscheidet sich am %s: Ziel "%s", Quelle "%s".', $date, $existing['name'], $name));
                }
            } else {
                $this->report->increment('public_holidays.inserted');
                if ($this->execute) {
                    $now = gmdate('Y-m-d H:i:s');
                    $insert = $this->target->prepare(
                        'INSERT INTO public_holiday(region, day, name, source, legacy_public_holiday_id, created_at, updated_at)
                         VALUES(?,?,?,\'legacy-mysql\',?,?,?)'
                    );
                    $insert->execute([$region, $date, $name, $legacyId, $now, $now]);
                }
            }
            $this->report->sample('public_holidays', ['date' => $date, 'name' => $name, 'legacy_id' => $legacyId]);
        }
    }

    private function importWorktime(): void
    {
        if ((!$this->enabled('work_sessions') && !$this->enabled('absences')) || $this->tables['worktime'] === null) {
            return;
        }

        $table = $this->tables['worktime'];
        $mapping = $this->columns['worktime'];
        $statement = $this->schema->select($table, $mapping, ['persid', 'date', 'id']);

        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $this->report->increment('worktime.read');
            $personnelNumber = trim((string)($row['persid'] ?? ''));
            $employeeId = $this->employeeMap[$personnelNumber] ?? null;
            if ($employeeId === null) {
                $this->report->warning('Arbeitszeit fuer unbekannte Personalnummer ' . $personnelNumber . ' wurde uebersprungen.');
                $this->report->increment('worktime.skipped_unknown_employee');
                continue;
            }

            $date = $this->converter->date($row['date'] ?? null);
            if ($date === null) {
                $this->report->warning('Arbeitszeit mit ungueltigem Datum fuer Personalnummer ' . $personnelNumber . ' wurde uebersprungen.');
                $this->report->increment('worktime.skipped_invalid_date');
                continue;
            }
            if (!$this->dateAllowed($date)) {
                $this->report->increment('worktime.filtered');
                continue;
            }

            $legacyId = $this->legacyId('worktime', $row['id'] ?? null, [
                'persid' => $personnelNumber,
                'date' => $date,
                'start' => $row['work_start'] ?? null,
                'end' => $row['work_end'] ?? null,
            ]);
            $flags = [
                'holiday' => $this->converter->bool($row['holiday'] ?? false),
                'ill' => $this->converter->bool($row['ill'] ?? false),
                'other' => $this->converter->bool($row['other'] ?? false),
                'halfholiday' => $this->converter->bool($row['halfholiday'] ?? false),
                'school' => $this->converter->bool($row['school'] ?? false),
            ];
            $activeFlagCount = count(array_filter($flags));
            if ($activeFlagCount > 1 && !($flags['holiday'] && $flags['halfholiday'] && $activeFlagCount === 2)) {
                $this->report->warning('Mehrere Abwesenheitskennzeichen in worktime-ID ' . $legacyId . ': ' . json_encode($flags));
            }

            $startedAt = $this->converter->utcDateTime($row['work_start'] ?? null, $date);
            $endedAt = $this->converter->utcDateTime($row['work_end'] ?? null, $date);
            if ($startedAt !== null && $endedAt !== null && $endedAt < $startedAt) {
                $endedAt = (new DateTimeImmutable($endedAt, new DateTimeZone('UTC')))
                    ->modify('+1 day')
                    ->format('Y-m-d H:i:s');
            }

            [$absenceType, $portion, $publicHolidayOnly] = $this->classifyAbsence($flags, $date, $startedAt, $endedAt, $legacyId);
            if ($absenceType !== null && $this->enabled('absences')) {
                $this->upsertAbsence($employeeId, $legacyId, $date, $absenceType, $portion);
            }

            $fullAbsence = $absenceType !== null && $portion === 'FULL';
            $shouldImportSession = $this->enabled('work_sessions') && !$fullAbsence && !$publicHolidayOnly;
            if ($shouldImportSession) {
                if ($startedAt === null) {
                    if ($absenceType === null) {
                        $this->report->warning('Arbeitszeit ohne Start fuer Personalnummer ' . $personnelNumber . ' am ' . $date . ' wurde uebersprungen.');
                    }
                    $this->report->increment('work_sessions.skipped_no_start');
                } else {
                    $autoClosed = false;
                    if ($endedAt === null) {
                        [$endedAt, $autoClosed] = $this->resolveMissingWorkEnd($date, $startedAt);
                    }
                    if ($endedAt !== null && !$this->validDuration($startedAt, $endedAt, (float)$this->get('max_work_session_hours', 24.0))) {
                        $this->report->warning('Unplausible Arbeitsdauer fuer ' . $personnelNumber . ' am ' . $date . ' wurde uebersprungen. Start=' . $startedAt . ', Ende=' . $endedAt);
                        $this->report->increment('work_sessions.skipped_invalid_duration');
                    } else {
                        $sessionId = $this->upsertWorkSession($employeeId, $legacyId, $startedAt, $endedAt, $autoClosed);
                        if ($sessionId !== null) {
                            $this->registerSession($personnelNumber, $date, $sessionId, $startedAt, $endedAt);
                        }
                    }
                }
            }

            $this->report->sample('worktime_conversion', [
                'legacy_id' => $legacyId,
                'personnel_number' => $personnelNumber,
                'date' => $date,
                'raw_start' => $row['work_start'] ?? null,
                'raw_end' => $row['work_end'] ?? null,
                'utc_start' => $startedAt,
                'utc_end' => $endedAt,
                'local_start' => $this->converter->localTime($startedAt),
                'local_end' => $this->converter->localTime($endedAt),
                'absence' => $absenceType,
                'portion' => $portion,
                'flags' => $flags,
            ]);
        }
    }

    private function classifyAbsence(array $flags, string $date, ?string $startedAt, ?string $endedAt, int $legacyId): array
    {
        if ($flags['halfholiday']) {
            return ['VACATION', $this->halfDayPortion($startedAt, $endedAt, $legacyId), false];
        }
        if ($flags['holiday']) {
            return ['VACATION', 'FULL', false];
        }
        if ($flags['ill']) {
            return ['SICK', 'FULL', false];
        }
        if ($flags['school']) {
            return ['SCHOOL', 'FULL', false];
        }
        if ($flags['other']) {
            if (isset($this->publicHolidayDays[$date])) {
                return [null, 'FULL', true];
            }
            return ['OTHER', 'FULL', false];
        }
        return [null, 'FULL', false];
    }

    private function halfDayPortion(?string $startedAt, ?string $endedAt, int $legacyId): string
    {
        $split = (string)$this->get('half_day_split', '12:00');
        $start = $this->converter->localTime($startedAt);
        $end = $this->converter->localTime($endedAt);
        if ($start !== null && substr($start, 0, 5) >= $split) {
            return 'AM';
        }
        if ($end !== null && substr($end, 0, 5) <= $split) {
            return 'PM';
        }
        $fallback = strtoupper((string)$this->get('unknown_half_day_portion', 'PM'));
        if (!in_array($fallback, ['AM', 'PM'], true)) {
            $fallback = 'PM';
        }
        $this->report->warning('Halber Urlaub in worktime-ID ' . $legacyId . ' konnte nicht eindeutig AM/PM zugeordnet werden. Verwendet: ' . $fallback);
        return $fallback;
    }

    private function upsertAbsence(int $employeeId, int $legacyId, string $date, string $type, string $portion): void
    {
        if ($employeeId > 0) {
            $statement = $this->target->prepare('SELECT * FROM absence WHERE legacy_worktime_id=?');
            $statement->execute([$legacyId]);
            $existing = $statement->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $this->report->increment('absences.skipped_existing_legacy');
                return;
            }

            $statement = $this->target->prepare(
                'SELECT * FROM absence WHERE employee_id=? AND type=? AND portion=? AND start_date=? AND end_date=? LIMIT 1'
            );
            $statement->execute([$employeeId, $type, $portion, $date, $date]);
            $existing = $statement->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $this->report->increment('absences.merged_exact');
                if ($this->execute && $existing['legacy_worktime_id'] === null) {
                    $update = $this->target->prepare('UPDATE absence SET legacy_worktime_id=?, updated_at=? WHERE id=?');
                    $update->execute([$legacyId, gmdate('Y-m-d H:i:s'), $existing['id']]);
                }
                return;
            }
        }

        $this->report->increment('absences.inserted');
        if (!$this->execute) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $insert = $this->target->prepare(
            'INSERT INTO absence(
                employee_id, type, portion, start_date, end_date, note, source,
                legacy_worktime_id, credit_minutes_override, created_at, updated_at
             ) VALUES(?,?,?,?,?,\'Import aus alter Stempeluhr\',\'legacy-mysql\',?,NULL,?,?)'
        );
        $insert->execute([$employeeId, $type, $portion, $date, $date, $legacyId, $now, $now]);
    }

    private function upsertWorkSession(int $employeeId, int $legacyId, string $startedAt, ?string $endedAt, bool $autoClosed): ?int
    {
        if ($employeeId > 0) {
            $statement = $this->target->prepare('SELECT * FROM work_session WHERE legacy_worktime_id=?');
            $statement->execute([$legacyId]);
            $existing = $statement->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $this->report->increment('work_sessions.skipped_existing_legacy');
                return (int)$existing['id'];
            }

            $statement = $this->target->prepare('SELECT * FROM work_session WHERE employee_id=? AND started_at=? LIMIT 1');
            $statement->execute([$employeeId, $startedAt]);
            $existing = $statement->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if (($existing['ended_at'] ?? null) !== $endedAt) {
                    $this->report->error(sprintf(
                        'Arbeitszeitkonflikt fuer Ziel-Mitarbeiter %d bei %s: Ziel-Ende=%s, Quelle-Ende=%s.',
                        $employeeId,
                        $startedAt,
                        $existing['ended_at'] ?? 'NULL',
                        $endedAt ?? 'NULL'
                    ));
                    return null;
                }
                $this->report->increment('work_sessions.merged_exact');
                if ($this->execute && $existing['legacy_worktime_id'] === null) {
                    $update = $this->target->prepare('UPDATE work_session SET legacy_worktime_id=?, updated_at=? WHERE id=?');
                    $update->execute([$legacyId, gmdate('Y-m-d H:i:s'), $existing['id']]);
                }
                return (int)$existing['id'];
            }
        }

        $this->report->increment('work_sessions.inserted');
        if (!$this->execute) {
            return $this->virtualId--;
        }
        $now = gmdate('Y-m-d H:i:s');
        $note = $autoClosed ? 'Legacy-Import: fehlendes Ende automatisch gesetzt.' : '';
        $insert = $this->target->prepare(
            'INSERT INTO work_session(employee_id, started_at, ended_at, source, legacy_worktime_id, note, created_at, updated_at)
             VALUES(?,?,?,\'legacy-mysql\',?,?,?,?)'
        );
        $insert->execute([$employeeId, $startedAt, $endedAt, $legacyId, $note, $now, $now]);
        return (int)$this->target->lastInsertId();
    }

    private function resolveMissingWorkEnd(string $date, string $startedAt): array
    {
        $policy = (string)$this->get('open_session_policy', 'close-default');
        if ($policy === 'skip') {
            $this->report->warning('Offene alte Arbeitssitzung am ' . $date . ' wurde gemaess Konfiguration uebersprungen.');
            return [null, false];
        }
        if ($policy === 'open') {
            return [null, false];
        }

        $weekday = (int)(new DateTimeImmutable($date))->format('N');
        $endByWeekday = (array)$this->get('default_end_by_weekday', [
            1 => '17:00:00', 2 => '17:00:00', 3 => '17:00:00', 4 => '17:00:00',
            5 => '12:00:00', 6 => '17:00:00', 7 => '17:00:00',
        ]);
        $time = (string)($endByWeekday[$weekday] ?? '17:00:00');
        $endedAt = $this->converter->localDateTimeToUtc($date, $time);
        if ($endedAt < $startedAt) {
            $endedAt = $startedAt;
        }
        $this->report->warning('Fehlendes Arbeitsende am ' . $date . ' wurde auf ' . $time . ' Ortszeit gesetzt.');
        return [$endedAt, true];
    }

    private function importBreaks(): void
    {
        if (!$this->enabled('breaks') || $this->tables['breaks'] === null) {
            return;
        }
        $table = $this->tables['breaks'];
        $mapping = $this->columns['breaks'];
        $statement = $this->schema->select($table, $mapping, ['persid', 'date', 'id']);

        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $this->report->increment('breaks.read');
            $personnelNumber = trim((string)($row['persid'] ?? ''));
            $employeeId = $this->employeeMap[$personnelNumber] ?? null;
            if ($employeeId === null) {
                $this->report->warning('Pause fuer unbekannte Personalnummer ' . $personnelNumber . ' wurde uebersprungen.');
                $this->report->increment('breaks.skipped_unknown_employee');
                continue;
            }
            $date = $this->converter->date($row['date'] ?? null);
            if ($date === null || !$this->dateAllowed($date)) {
                $this->report->increment($date === null ? 'breaks.skipped_invalid_date' : 'breaks.filtered');
                continue;
            }
            $startedAt = $this->converter->utcDateTime($row['break_start'] ?? null, $date);
            $endedAt = $this->converter->utcDateTime($row['break_end'] ?? null, $date);
            if ($startedAt === null || $endedAt === null) {
                $this->report->warning('Offene oder ungueltige Pause fuer ' . $personnelNumber . ' am ' . $date . ' wurde uebersprungen.');
                $this->report->increment('breaks.skipped_open_or_invalid');
                continue;
            }
            if ($endedAt < $startedAt) {
                $endedAt = (new DateTimeImmutable($endedAt, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s');
            }
            if (!$this->validDuration($startedAt, $endedAt, (float)$this->get('max_break_hours', 8.0))) {
                $this->report->warning('Unplausible Pause fuer ' . $personnelNumber . ' am ' . $date . ' wurde uebersprungen.');
                $this->report->increment('breaks.skipped_invalid_duration');
                continue;
            }

            $session = $this->findSessionForBreak($personnelNumber, $employeeId, $date, $startedAt);
            if ($session === null) {
                $this->report->warning('Pause ohne zugehoerige Arbeitssitzung fuer ' . $personnelNumber . ' am ' . $date . ' wurde uebersprungen.');
                $this->report->increment('breaks.skipped_without_work_session');
                continue;
            }

            $legacyId = $this->legacyId('break', $row['id'] ?? null, [
                'persid' => $personnelNumber,
                'date' => $date,
                'start' => $row['break_start'] ?? null,
                'end' => $row['break_end'] ?? null,
            ]);
            $this->upsertBreak((int)$session['id'], $legacyId, $startedAt, $endedAt);
            $this->report->sample('break_conversion', [
                'legacy_id' => $legacyId,
                'personnel_number' => $personnelNumber,
                'date' => $date,
                'utc_start' => $startedAt,
                'utc_end' => $endedAt,
                'local_start' => $this->converter->localTime($startedAt),
                'local_end' => $this->converter->localTime($endedAt),
                'work_session_id' => $session['id'],
            ]);
        }
    }

    private function upsertBreak(int $workSessionId, int $legacyId, string $startedAt, string $endedAt): void
    {
        if ($workSessionId > 0) {
            $statement = $this->target->prepare('SELECT * FROM break_session WHERE legacy_break_id=?');
            $statement->execute([$legacyId]);
            $existing = $statement->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $this->report->increment('breaks.skipped_existing_legacy');
                return;
            }

            $statement = $this->target->prepare(
                'SELECT * FROM break_session WHERE work_session_id=? AND started_at=? AND ended_at=? LIMIT 1'
            );
            $statement->execute([$workSessionId, $startedAt, $endedAt]);
            $existing = $statement->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $this->report->increment('breaks.merged_exact');
                if ($this->execute && $existing['legacy_break_id'] === null) {
                    $update = $this->target->prepare('UPDATE break_session SET legacy_break_id=?, updated_at=? WHERE id=?');
                    $update->execute([$legacyId, gmdate('Y-m-d H:i:s'), $existing['id']]);
                }
                return;
            }
        }

        $this->report->increment('breaks.inserted');
        if (!$this->execute) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $insert = $this->target->prepare(
            'INSERT INTO break_session(work_session_id, started_at, ended_at, source, legacy_break_id, created_at, updated_at)
             VALUES(?,?,?,\'legacy-mysql\',?,?,?)'
        );
        $insert->execute([$workSessionId, $startedAt, $endedAt, $legacyId, $now, $now]);
    }

    private function importOvertime(): void
    {
        if (!$this->enabled('overtime') || $this->tables['overtime'] === null) {
            return;
        }
        $table = $this->tables['overtime'];
        $mapping = $this->columns['overtime'];
        $statement = $this->schema->select($table, $mapping, ['date', 'id']);
        $unit = (string)$this->get('overtime_value_unit', 'minutes');

        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $this->report->increment('overtime.read');
            $date = $this->converter->date($row['date'] ?? null);
            if ($date === null || !$this->dateAllowed($date)) {
                $this->report->increment($date === null ? 'overtime.skipped_invalid_date' : 'overtime.filtered');
                continue;
            }
            $legacyId = $this->legacyId('overtime', $row['id'] ?? null, ['date' => $date, 'note' => $row['note'] ?? '']);
            $credit = (int)round($this->converter->number($row['credit'] ?? null, 0.0));
            if ($unit === 'hours') {
                $credit *= 60;
            }
            $note = trim((string)($row['note'] ?? ''));

            $find = $this->target->prepare('SELECT * FROM overtime_event WHERE legacy_overtime_id=?');
            $find->execute([$legacyId]);
            $existing = $find->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($existing !== null) {
                $this->report->increment('overtime.skipped_existing_legacy');
                continue;
            }

            $this->report->increment('overtime.inserted');
            if ($this->execute) {
                $now = gmdate('Y-m-d H:i:s');
                $insert = $this->target->prepare(
                    'INSERT INTO overtime_event(day, note, credit_minutes, source, legacy_overtime_id, created_at, updated_at)
                     VALUES(?,?,?,\'legacy-mysql\',?,?,?)'
                );
                $insert->execute([$date, $note, $credit, $legacyId, $now, $now]);
            }
        }
    }

    private function findSessionForBreak(string $personnelNumber, int $employeeId, string $date, string $breakStart): ?array
    {
        $key = $personnelNumber . '|' . $date;
        $candidates = $this->sessionMap[$key] ?? [];
        if (!$candidates && $employeeId > 0) {
            [$from, $to] = $this->converter->localDayUtcBounds($date);
            $statement = $this->target->prepare(
                'SELECT id, started_at, ended_at FROM work_session
                 WHERE employee_id=? AND started_at>=? AND started_at<? ORDER BY started_at'
            );
            $statement->execute([$employeeId, $from, $to]);
            $candidates = $statement->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($candidates as $candidate) {
            $end = $candidate['ended_at'] ?? null;
            if ((string)$candidate['started_at'] <= $breakStart && ($end === null || (string)$end >= $breakStart)) {
                return $candidate;
            }
        }
        return $candidates[0] ?? null;
    }

    private function registerSession(string $personnelNumber, string $date, int $id, string $startedAt, ?string $endedAt): void
    {
        $key = $personnelNumber . '|' . $date;
        $this->sessionMap[$key][] = [
            'id' => $id,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
        ];
    }

    private function findTargetEmployeeByLegacyId(int $legacyId): ?array
    {
        $statement = $this->target->prepare('SELECT * FROM employee WHERE legacy_employee_id=?');
        $statement->execute([$legacyId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function findTargetEmployeeByPersonnelNumber(string $personnelNumber): ?array
    {
        $statement = $this->target->prepare('SELECT * FROM employee WHERE personnel_number=? COLLATE NOCASE');
        $statement->execute([$personnelNumber]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function findPublicHoliday(int $legacyId, string $region, string $date, string $name): ?array
    {
        $statement = $this->target->prepare('SELECT * FROM public_holiday WHERE legacy_public_holiday_id=?');
        $statement->execute([$legacyId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        $statement = $this->target->prepare('SELECT * FROM public_holiday WHERE region=? AND day=? AND name=? LIMIT 1');
        $statement->execute([$region, $date, $name]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        $statement = $this->target->prepare('SELECT * FROM public_holiday WHERE region=? AND day=? ORDER BY id LIMIT 1');
        $statement->execute([$region, $date]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function validateTargetSchema(): void
    {
        $required = [
            'employee' => ['personnel_number', 'legacy_employee_id', 'weekly_hours'],
            'employee_schedule' => ['employee_id', 'valid_from', 'target_minutes', 'source'],
            'vacation_account' => ['employee_id', 'year', 'legacy_entitlement_days'],
            'work_session' => ['employee_id', 'started_at', 'legacy_worktime_id'],
            'break_session' => ['work_session_id', 'legacy_break_id'],
            'absence' => ['employee_id', 'portion', 'legacy_worktime_id'],
            'public_holiday' => ['day', 'legacy_public_holiday_id'],
            'overtime_event' => ['day', 'legacy_overtime_id'],
            'import_batch' => ['source_system', 'status', 'summary'],
        ];
        foreach ($required as $table => $columns) {
            $tableExists = $this->target->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
            $tableExists->execute([$table]);
            if ((int)$tableExists->fetchColumn() === 0) {
                throw new RuntimeException('Ziel-Datenbank hat nicht die erwartete Schema-Version. Tabelle fehlt: ' . $table);
            }
            $found = [];
            foreach ($this->target->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $found[] = (string)$row['name'];
            }
            foreach ($columns as $column) {
                if (!in_array($column, $found, true)) {
                    throw new RuntimeException('Ziel-Datenbank hat nicht die erwartete Schema-Version. Spalte fehlt: ' . $table . '.' . $column);
                }
            }
        }
    }

    private function validateTargetIntegrity(): void
    {
        $integrity = (string)$this->target->query('PRAGMA integrity_check')->fetchColumn();
        if ($integrity !== 'ok') {
            throw new RuntimeException('SQLite integrity_check fehlgeschlagen: ' . $integrity);
        }
        $foreignKeys = $this->target->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
        if ($foreignKeys) {
            throw new RuntimeException('SQLite foreign_key_check meldet ' . count($foreignKeys) . ' Fehler.');
        }
        $this->report->info('SQLite integrity_check: ok; foreign_key_check: keine Fehler.');
    }

    private function enabled(string $name): bool
    {
        $import = (array)$this->get('import', []);
        return !array_key_exists($name, $import) || (bool)$import[$name];
    }

    private function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    private function dateAllowed(string $date): bool
    {
        $from = trim((string)$this->get('from_date', ''));
        $to = trim((string)$this->get('to_date', ''));
        return ($from === '' || $date >= $from) && ($to === '' || $date <= $to);
    }

    private function legacyId(string $entity, mixed $value, array $fingerprint): int
    {
        if ($value !== null && trim((string)$value) !== '' && is_numeric($value)) {
            $id = (int)$value;
            if ($id !== 0) {
                return $id;
            }
        }
        $hex = substr(hash('sha256', $entity . '|' . json_encode($fingerprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 15);
        $number = 0;
        foreach (str_split($hex) as $digit) {
            $number = ($number * 16) + hexdec($digit);
        }
        return -max(1, $number);
    }

    private function samePersonName(string $left, string $right): bool
    {
        $normalize = static function (string $value): string {
            $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
            return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        };
        return $normalize($left) === $normalize($right);
    }

    private function defaultScheduleMinutes(float $weeklyHours): array
    {
        $weeklyMinutes = max(0, (int)round($weeklyHours * 60));
        if ($weeklyMinutes === 0) {
            return [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0];
        }
        $weights = [1 => 510, 2 => 510, 3 => 510, 4 => 510, 5 => 240];
        $total = array_sum($weights);
        $result = [];
        $assigned = 0;
        foreach ($weights as $weekday => $weight) {
            $minutes = (int)round($weeklyMinutes * ($weight / $total));
            $result[$weekday] = $minutes;
            $assigned += $minutes;
        }
        $result[5] += $weeklyMinutes - $assigned;
        $result[6] = 0;
        $result[7] = 0;
        return $result;
    }

    private function minutesToTime(int $minutes): string
    {
        $minutes = max(0, min(1439, $minutes));
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function validDuration(string $start, string $end, float $maxHours): bool
    {
        $startTime = strtotime($start . ' UTC');
        $endTime = strtotime($end . ' UTC');
        if ($startTime === false || $endTime === false || $endTime < $startTime) {
            return false;
        }
        return ($endTime - $startTime) <= (int)round($maxHours * 3600);
    }
}
