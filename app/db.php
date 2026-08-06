<?php
declare(strict_types=1);

const DATABASE_SCHEMA_VERSION = 6;

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $file = (string)cfg('database_file');
    $folder = dirname($file);
    if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
        throw new RuntimeException('Datenbankordner konnte nicht erstellt werden');
    }

    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('Schema konnte nicht geladen werden');
    }

    if (table_exists($pdo, 'employee')) {
        migrate_database($pdo);
    }

    $pdo->exec($schema);
    $pdo->exec('PRAGMA user_version = ' . DATABASE_SCHEMA_VERSION);
    seed_database($pdo);

    return $pdo;
}

function migrate_database(PDO $pdo): void
{
    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    $coreSchemaCurrent = table_has_column($pdo, 'employee', 'personnel_number')
        && table_has_column($pdo, 'employee', 'weekly_hours')
        && table_has_column($pdo, 'work_session', 'note')
        && table_has_column($pdo, 'break_session', 'source')
        && table_has_column($pdo, 'absence', 'portion')
        && table_has_column($pdo, 'public_holiday', 'source');

    // Versions 3 and 4 extend the persistent vacation-request workflow. Versions
    // 5 and 6 only change application rules around employee schedules and can be
    // applied in place without rebuilding the established time-tracking tables.
    if ($version >= 2 && $coreSchemaCurrent) {
        migrate_vacation_requests_v4($pdo);
        $pdo->exec('PRAGMA user_version = ' . DATABASE_SCHEMA_VERSION);
        return;
    }

    $nowExpression = "strftime('%Y-%m-%d %H:%M:%S','now')";
    $employeeColumns = table_columns($pdo, 'employee');
    $workColumns = table_columns($pdo, 'work_session');
    $breakColumns = table_columns($pdo, 'break_session');
    $absenceColumns = table_columns($pdo, 'absence');
    $holidayColumns = table_columns($pdo, 'public_holiday');

    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->beginTransaction();
    try {
        foreach (['employee_new', 'work_session_new', 'break_session_new', 'absence_new', 'public_holiday_new'] as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }

        $pdo->exec("CREATE TABLE employee_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            personnel_number TEXT UNIQUE COLLATE NOCASE,
            name TEXT NOT NULL,
            email TEXT UNIQUE COLLATE NOCASE,
            password_hash TEXT NOT NULL DEFAULT '',
            role TEXT NOT NULL DEFAULT 'employee' CHECK(role IN ('admin', 'employee')),
            timezone TEXT NOT NULL DEFAULT 'Europe/Berlin',
            holiday_region TEXT NOT NULL DEFAULT 'DE-BY-KF',
            department TEXT NOT NULL DEFAULT '',
            phone TEXT NOT NULL DEFAULT '',
            weekly_hours REAL NOT NULL DEFAULT 38 CHECK(weekly_hours >= 0 AND weekly_hours <= 168),
            is_trainee INTEGER NOT NULL DEFAULT 0 CHECK(is_trainee IN (0, 1)),
            special_time INTEGER NOT NULL DEFAULT 0 CHECK(special_time IN (0, 1)),
            active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0, 1)),
            login_enabled INTEGER NOT NULL DEFAULT 1 CHECK(login_enabled IN (0, 1)),
            must_change_password INTEGER NOT NULL DEFAULT 0 CHECK(must_change_password IN (0, 1)),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

        $employeeSelect = [
            column_expression($employeeColumns, 'id', 'NULL'),
            column_expression($employeeColumns, 'personnel_number', 'NULL'),
            column_expression($employeeColumns, 'name', "''"),
            column_expression($employeeColumns, 'email', 'NULL'),
            column_expression($employeeColumns, 'password_hash', "''"),
            column_expression($employeeColumns, 'role', "'employee'"),
            column_expression($employeeColumns, 'timezone', "'Europe/Berlin'"),
            column_expression($employeeColumns, 'holiday_region', "'DE-BY-KF'"),
            column_expression($employeeColumns, 'department', "''"),
            column_expression($employeeColumns, 'phone', "''"),
            column_expression($employeeColumns, 'weekly_hours', '38'),
            column_expression($employeeColumns, 'is_trainee', '0'),
            column_expression($employeeColumns, 'special_time', '0'),
            column_expression($employeeColumns, 'active', '1'),
            column_expression($employeeColumns, 'login_enabled', '1'),
            column_expression($employeeColumns, 'must_change_password', '0'),
            column_expression($employeeColumns, 'created_at', $nowExpression),
            column_expression($employeeColumns, 'updated_at', column_expression($employeeColumns, 'created_at', $nowExpression)),
        ];
        $pdo->exec('INSERT INTO employee_new(id, personnel_number, name, email, password_hash, role, timezone, holiday_region, department, phone, weekly_hours, is_trainee, special_time, active, login_enabled, must_change_password, created_at, updated_at) SELECT ' . implode(', ', $employeeSelect) . ' FROM employee');

        $pdo->exec("CREATE TABLE work_session_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id INTEGER NOT NULL,
            started_at TEXT NOT NULL,
            ended_at TEXT,
            source TEXT NOT NULL DEFAULT 'web',
            note TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(employee_id) REFERENCES employee(id) ON DELETE CASCADE,
            CHECK(ended_at IS NULL OR ended_at >= started_at)
        )");
        if ($workColumns) {
            $workSelect = [
                column_expression($workColumns, 'id', 'NULL'),
                column_expression($workColumns, 'employee_id', '0'),
                column_expression($workColumns, 'started_at', $nowExpression),
                column_expression($workColumns, 'ended_at', 'NULL'),
                column_expression($workColumns, 'source', "'web'"),
                column_expression($workColumns, 'note', "''"),
                column_expression($workColumns, 'created_at', column_expression($workColumns, 'started_at', $nowExpression)),
                column_expression($workColumns, 'updated_at', "COALESCE(" . column_expression($workColumns, 'ended_at', 'NULL') . ', ' . column_expression($workColumns, 'started_at', $nowExpression) . ')'),
            ];
            $pdo->exec('INSERT INTO work_session_new(id, employee_id, started_at, ended_at, source, note, created_at, updated_at) SELECT ' . implode(', ', $workSelect) . ' FROM work_session');
        }

        $pdo->exec("CREATE TABLE break_session_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            work_session_id INTEGER NOT NULL,
            started_at TEXT NOT NULL,
            ended_at TEXT,
            source TEXT NOT NULL DEFAULT 'web',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(work_session_id) REFERENCES work_session(id) ON DELETE CASCADE,
            CHECK(ended_at IS NULL OR ended_at >= started_at)
        )");
        if ($breakColumns) {
            $breakSelect = [
                column_expression($breakColumns, 'id', 'NULL'),
                column_expression($breakColumns, 'work_session_id', '0'),
                column_expression($breakColumns, 'started_at', $nowExpression),
                column_expression($breakColumns, 'ended_at', 'NULL'),
                column_expression($breakColumns, 'source', "'web'"),
                column_expression($breakColumns, 'created_at', column_expression($breakColumns, 'started_at', $nowExpression)),
                column_expression($breakColumns, 'updated_at', "COALESCE(" . column_expression($breakColumns, 'ended_at', 'NULL') . ', ' . column_expression($breakColumns, 'started_at', $nowExpression) . ')'),
            ];
            $pdo->exec('INSERT INTO break_session_new(id, work_session_id, started_at, ended_at, source, created_at, updated_at) SELECT ' . implode(', ', $breakSelect) . ' FROM break_session');
        }

        $pdo->exec("CREATE TABLE absence_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id INTEGER NOT NULL,
            type TEXT NOT NULL CHECK(type IN ('VACATION', 'SICK', 'SCHOOL', 'OTHER')),
            portion TEXT NOT NULL DEFAULT 'FULL' CHECK(portion IN ('FULL', 'AM', 'PM')),
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            note TEXT NOT NULL DEFAULT '',
            source TEXT NOT NULL DEFAULT 'web',
            credit_minutes_override INTEGER CHECK(credit_minutes_override IS NULL OR credit_minutes_override >= 0),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(employee_id) REFERENCES employee(id) ON DELETE CASCADE,
            CHECK(end_date >= start_date),
            CHECK(portion = 'FULL' OR start_date = end_date),
            CHECK(portion = 'FULL' OR type = 'VACATION')
        )");
        if ($absenceColumns) {
            $absenceSelect = [
                column_expression($absenceColumns, 'id', 'NULL'),
                column_expression($absenceColumns, 'employee_id', '0'),
                column_expression($absenceColumns, 'type', "'OTHER'"),
                column_expression($absenceColumns, 'portion', "'FULL'"),
                column_expression($absenceColumns, 'start_date', "'1970-01-01'"),
                column_expression($absenceColumns, 'end_date', "'1970-01-01'"),
                column_expression($absenceColumns, 'note', "''"),
                column_expression($absenceColumns, 'source', "'web'"),
                column_expression($absenceColumns, 'credit_minutes_override', 'NULL'),
                column_expression($absenceColumns, 'created_at', $nowExpression),
                column_expression($absenceColumns, 'updated_at', column_expression($absenceColumns, 'created_at', $nowExpression)),
            ];
            $where = in_array('status', $absenceColumns, true) ? " WHERE status <> 'REJECTED'" : '';
            $pdo->exec('INSERT INTO absence_new(id, employee_id, type, portion, start_date, end_date, note, source, credit_minutes_override, created_at, updated_at) SELECT ' . implode(', ', $absenceSelect) . ' FROM absence' . $where);
        }

        $pdo->exec("CREATE TABLE public_holiday_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            region TEXT NOT NULL,
            day TEXT NOT NULL,
            name TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT 'system',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(region, day, name)
        )");
        if ($holidayColumns) {
            $holidaySelect = [
                column_expression($holidayColumns, 'id', 'NULL'),
                column_expression($holidayColumns, 'region', "'DE-BY-KF'"),
                column_expression($holidayColumns, 'day', "'1970-01-01'"),
                column_expression($holidayColumns, 'name', "''"),
                column_expression($holidayColumns, 'source', "'system'"),
                column_expression($holidayColumns, 'created_at', $nowExpression),
                column_expression($holidayColumns, 'updated_at', column_expression($holidayColumns, 'created_at', $nowExpression)),
            ];
            $pdo->exec('INSERT OR IGNORE INTO public_holiday_new(id, region, day, name, source, created_at, updated_at) SELECT ' . implode(', ', $holidaySelect) . ' FROM public_holiday');
        }

        foreach (['break_session', 'absence', 'work_session', 'employee', 'public_holiday'] as $table) {
            if (table_exists($pdo, $table)) {
                $pdo->exec('DROP TABLE ' . $table);
            }
        }

        $pdo->exec('ALTER TABLE employee_new RENAME TO employee');
        $pdo->exec('ALTER TABLE work_session_new RENAME TO work_session');
        $pdo->exec('ALTER TABLE break_session_new RENAME TO break_session');
        $pdo->exec('ALTER TABLE absence_new RENAME TO absence');
        $pdo->exec('ALTER TABLE public_holiday_new RENAME TO public_holiday');
        $pdo->exec('PRAGMA user_version = ' . DATABASE_SCHEMA_VERSION);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}

function migrate_vacation_requests_v4(PDO $pdo): void
{
    if (!table_exists($pdo, 'vacation_request')) {
        return;
    }

    $columns = table_columns($pdo, 'vacation_request');
    $additions = [
        'request_type' => "ALTER TABLE vacation_request ADD COLUMN request_type TEXT NOT NULL DEFAULT 'CREATE' CHECK(request_type IN ('CREATE', 'CHANGE', 'DELETE'))",
        'target_absence_id' => 'ALTER TABLE vacation_request ADD COLUMN target_absence_id INTEGER REFERENCES absence(id) ON DELETE SET NULL',
        'original_start_date' => 'ALTER TABLE vacation_request ADD COLUMN original_start_date TEXT',
        'original_end_date' => 'ALTER TABLE vacation_request ADD COLUMN original_end_date TEXT',
        'original_portion' => "ALTER TABLE vacation_request ADD COLUMN original_portion TEXT CHECK(original_portion IS NULL OR original_portion IN ('FULL', 'AM', 'PM'))",
        'original_note' => "ALTER TABLE vacation_request ADD COLUMN original_note TEXT NOT NULL DEFAULT ''",
    ];

    foreach ($additions as $column => $sql) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec($sql);
        }
    }

    $pdo->exec("UPDATE vacation_request SET request_type='CREATE' WHERE request_type IS NULL OR trim(request_type)=''");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_vacation_request_target_status ON vacation_request(target_absence_id, status)');
}

function table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

function table_columns(PDO $pdo, string $table): array
{
    if (!table_exists($pdo, $table)) {
        return [];
    }
    return array_values(array_map(
        static fn(array $row): string => (string)$row['name'],
        $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll()
    ));
}

function table_has_column(PDO $pdo, string $table, string $column): bool
{
    return in_array($column, table_columns($pdo, $table), true);
}

function column_expression(array $columns, string $column, string $fallback): string
{
    return in_array($column, $columns, true) ? '"' . str_replace('"', '""', $column) . '"' : $fallback;
}

function seed_database(PDO $pdo): void
{
    $region = (string)cfg('default_holiday_region', 'DE-BY-KF');
    $now = gmdate('Y-m-d H:i:s');
    $createdDemoUsers = false;
    $count = (int)$pdo->query('SELECT COUNT(*) FROM employee')->fetchColumn();

    if ($count === 0 && (bool)cfg('seed_demo_users', true)) {
        $st = $pdo->prepare('INSERT INTO employee(personnel_number, name, email, password_hash, role, timezone, holiday_region, department, phone, weekly_hours, is_trainee, special_time, active, login_enabled, must_change_password, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,0,0,1,1,0,?,?)');
        $st->execute(['ADMIN', 'Administrator', 'admin@schule.local', password_hash('admin123', PASSWORD_DEFAULT), 'admin', 'Europe/Berlin', $region, 'Administration', '', 38, $now, $now]);
        $st->execute(['1000', 'Max Mustermann', 'max@schule.local', password_hash('max123', PASSWORD_DEFAULT), 'employee', 'Europe/Berlin', $region, '', '', 38, $now, $now]);
        $createdDemoUsers = true;
    }

    $st = $pdo->prepare("UPDATE employee SET holiday_region=?, updated_at=? WHERE holiday_region IS NULL OR trim(holiday_region)=''");
    $st->execute([$region, $now]);
    $pdo->prepare("UPDATE employee SET personnel_number='ADMIN', updated_at=? WHERE personnel_number IS NULL AND email='admin@schule.local'")->execute([$now]);
    $pdo->prepare("UPDATE employee SET personnel_number='1000', updated_at=? WHERE personnel_number IS NULL AND email='max@schule.local'")->execute([$now]);

    seed_work_rules($pdo, $now);
    seed_employee_structures($pdo, $now, $createdDemoUsers);
    seed_holidays($pdo, $region, $now);
}

function seed_work_rules(PDO $pdo, string $now): void
{
    $rules = [
        1 => ['07:30', '08:00', '17:00', 30, 510],
        2 => ['07:30', '08:00', '17:00', 30, 510],
        3 => ['07:30', '08:00', '17:00', 30, 510],
        4 => ['07:30', '08:00', '17:00', 30, 510],
        5 => ['07:30', '08:00', '12:00', 0, 240],
        6 => ['07:30', '08:00', '17:00', 0, 0],
        7 => ['07:30', '08:00', '17:00', 0, 0],
    ];
    $st = $pdo->prepare('INSERT OR IGNORE INTO work_rule(weekday, earliest_start, break_bonus_until, forgotten_end, base_break_minutes, default_target_minutes, updated_at) VALUES(?,?,?,?,?,?,?)');
    foreach ($rules as $weekday => $rule) {
        $st->execute([$weekday, ...$rule, $now]);
    }
}


function seed_employee_structures(PDO $pdo, string $now, bool $createdDemoUsers): void
{
    $employees = $pdo->query('SELECT id, personnel_number, email, role FROM employee')->fetchAll();
    $scheduleInsert = $pdo->prepare('INSERT OR IGNORE INTO employee_schedule(employee_id, valid_from, valid_to, weekday, target_minutes, planned_start, planned_end, source, created_at, updated_at) VALUES(?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)');
    $vacationInsert = $pdo->prepare('INSERT OR IGNORE INTO vacation_account(employee_id, year, entitlement_days, carryover_days, adjustment_days, note, source, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?)');
    $year = (int)date('Y');

    foreach ($employees as $employee) {
        $employeeId = (int)$employee['id'];
        $hasSchedule = (int)$pdo->query('SELECT COUNT(*) FROM employee_schedule WHERE employee_id=' . $employeeId)->fetchColumn() > 0;
        if (!$hasSchedule) {
            foreach (standard_schedule_minutes() as $weekday => $minutes) {
                $plannedStart = $minutes > 0 ? '08:00' : '';
                $plannedEnd = planned_end_for_minutes($minutes);
                $scheduleInsert->execute([$employeeId, '1970-01-01', $weekday, $minutes, $plannedStart, $plannedEnd, 'system', $now, $now]);
            }
        }

        $entitlement = (($createdDemoUsers || (string)$employee['email'] === 'max@schule.local') && (string)$employee['personnel_number'] === '1000')
            ? (float)cfg('default_vacation_entitlement', 30)
            : 0.0;
        $vacationInsert->execute([$employeeId, $year, $entitlement, 0, 0, '', 'system', $now, $now]);
    }
}

function standard_schedule_minutes(): array
{
    return [1 => 510, 2 => 510, 3 => 510, 4 => 510, 5 => 240, 6 => 0, 7 => 0];
}

function planned_end_for_minutes(int $targetMinutes): string
{
    if ($targetMinutes <= 0) {
        return '';
    }
    $baseBreakMinutes = $targetMinutes > 360 ? 30 : 0;
    return minutes_to_time((8 * 60) + $targetMinutes + $baseBreakMinutes);
}

function minutes_to_time(int $minutesFromMidnight): string
{
    $minutesFromMidnight = max(0, min(1439, $minutesFromMidnight));
    return sprintf('%02d:%02d', intdiv($minutesFromMidnight, 60), $minutesFromMidnight % 60);
}

function seed_holidays(PDO $pdo, string $region, string $now): void
{
    $firstYear = 2025;
    $lastYear = 2035;
    $expected = ($lastYear - $firstYear + 1) * 13;

    $st = $pdo->prepare('SELECT COUNT(*) FROM public_holiday WHERE region=? AND substr(day,1,4) BETWEEN ? AND ?');
    $st->execute([$region, (string)$firstYear, (string)$lastYear]);
    $rightCount = (int)$st->fetchColumn();

    if ($rightCount === $expected) {
        return;
    }

    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare("DELETE FROM public_holiday WHERE region=? AND source='system'");
        $delete->execute([$region]);
        $insert = $pdo->prepare('INSERT OR IGNORE INTO public_holiday(region, day, name, source, created_at, updated_at) VALUES(?,?,?,?,?,?)');
        for ($year = $firstYear; $year <= $lastYear; $year++) {
            foreach (kaufbeuren_holidays($year) as $holiday) {
                $insert->execute([$region, $holiday['day'], $holiday['name'], 'system', $now, $now]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function kaufbeuren_holidays(int $year): array
{
    $easter = easter_sunday($year);
    $days = [
        ['day' => sprintf('%04d-01-01', $year), 'name' => 'Neujahr'],
        ['day' => sprintf('%04d-01-06', $year), 'name' => 'Heilige Drei Könige'],
        ['day' => $easter->modify('-2 days')->format('Y-m-d'), 'name' => 'Karfreitag'],
        ['day' => $easter->modify('+1 day')->format('Y-m-d'), 'name' => 'Ostermontag'],
        ['day' => sprintf('%04d-05-01', $year), 'name' => 'Tag der Arbeit'],
        ['day' => $easter->modify('+39 days')->format('Y-m-d'), 'name' => 'Christi Himmelfahrt'],
        ['day' => $easter->modify('+50 days')->format('Y-m-d'), 'name' => 'Pfingstmontag'],
        ['day' => $easter->modify('+60 days')->format('Y-m-d'), 'name' => 'Fronleichnam'],
        ['day' => sprintf('%04d-08-15', $year), 'name' => 'Mariä Himmelfahrt'],
        ['day' => sprintf('%04d-10-03', $year), 'name' => 'Tag der Deutschen Einheit'],
        ['day' => sprintf('%04d-11-01', $year), 'name' => 'Allerheiligen'],
        ['day' => sprintf('%04d-12-25', $year), 'name' => '1. Weihnachtstag'],
        ['day' => sprintf('%04d-12-26', $year), 'name' => '2. Weihnachtstag'],
    ];

    usort($days, static fn(array $a, array $b): int => strcmp($a['day'], $b['day']));
    return $days;
}

function easter_sunday(int $year): DateTimeImmutable
{
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;

    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
}
