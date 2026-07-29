<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $file = (string)cfg('database_file');
    $folder = dirname($file);
    if (!is_dir($folder)) {
        mkdir($folder, 0775, true);
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
    $pdo->exec($schema);
    migrate_database($pdo);
    $pdo->exec($schema);
    seed_database($pdo);

    return $pdo;
}


function migrate_database(PDO $pdo): void
{
    $oldBreaks = table_has_column($pdo, 'break_session', 'break_type');
    $oldAbsences = table_has_column($pdo, 'absence', 'status');
    if (!$oldBreaks && !$oldAbsences) {
        return;
    }

    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->beginTransaction();
    try {
        if ($oldBreaks) {
            $pdo->exec('DROP TABLE IF EXISTS break_session_new');
            $pdo->exec("CREATE TABLE break_session_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                work_session_id INTEGER NOT NULL,
                started_at TEXT NOT NULL,
                ended_at TEXT,
                FOREIGN KEY(work_session_id) REFERENCES work_session(id) ON DELETE CASCADE
            )");
            $pdo->exec('INSERT INTO break_session_new(id, work_session_id, started_at, ended_at) SELECT id, work_session_id, started_at, ended_at FROM break_session');
            $pdo->exec('DROP TABLE break_session');
            $pdo->exec('ALTER TABLE break_session_new RENAME TO break_session');
        }

        if ($oldAbsences) {
            $pdo->exec('DROP TABLE IF EXISTS absence_new');
            $pdo->exec("CREATE TABLE absence_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id INTEGER NOT NULL,
                type TEXT NOT NULL CHECK(type IN ('VACATION', 'SICK', 'SCHOOL', 'OTHER')),
                start_date TEXT NOT NULL,
                end_date TEXT NOT NULL,
                note TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                FOREIGN KEY(employee_id) REFERENCES employee(id) ON DELETE CASCADE
            )");
            $pdo->exec("INSERT INTO absence_new(id, employee_id, type, start_date, end_date, note, created_at)
                SELECT id, employee_id, type, start_date, end_date, note, created_at
                FROM absence WHERE status <> 'REJECTED'");
            $pdo->exec('DROP TABLE absence');
            $pdo->exec('ALTER TABLE absence_new RENAME TO absence');
        }

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

function table_has_column(PDO $pdo, string $table, string $column): bool
{
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $row) {
        if (($row['name'] ?? '') === $column) {
            return true;
        }
    }
    return false;
}

function seed_database(PDO $pdo): void
{
    $region = 'DE-BY-KF';
    $count = (int)$pdo->query('SELECT COUNT(*) FROM employee')->fetchColumn();

    if ($count === 0) {
        $now = gmdate('Y-m-d H:i:s');
        $st = $pdo->prepare('INSERT INTO employee(name, email, password_hash, role, timezone, holiday_region, active, created_at) VALUES(?,?,?,?,?,?,1,?)');
        $st->execute(['Administrator', 'admin@schule.local', password_hash('admin123', PASSWORD_DEFAULT), 'admin', 'Europe/Berlin', $region, $now]);
        $st->execute(['Max Mustermann', 'max@schule.local', password_hash('max123', PASSWORD_DEFAULT), 'employee', 'Europe/Berlin', $region, $now]);
    }

    $st = $pdo->prepare('UPDATE employee SET holiday_region=? WHERE holiday_region<>?');
    $st->execute([$region, $region]);

    seed_holidays($pdo, $region);
}

function seed_holidays(PDO $pdo, string $region): void
{
    $firstYear = 2025;
    $lastYear = 2035;
    $expected = ($lastYear - $firstYear + 1) * 13;

    $st = $pdo->prepare('SELECT COUNT(*) FROM public_holiday WHERE region=? AND substr(day,1,4) BETWEEN ? AND ?');
    $st->execute([$region, (string)$firstYear, (string)$lastYear]);
    $rightCount = (int)$st->fetchColumn();
    $wrongCount = (int)$pdo->query("SELECT COUNT(*) FROM public_holiday WHERE region<>'DE-BY-KF'")->fetchColumn();

    if ($rightCount === $expected && $wrongCount === 0) {
        return;
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM public_holiday');
        $insert = $pdo->prepare('INSERT INTO public_holiday(region, day, name) VALUES(?,?,?)');

        for ($year = $firstYear; $year <= $lastYear; $year++) {
            foreach (kaufbeuren_holidays($year) as $holiday) {
                $insert->execute([$region, $holiday['day'], $holiday['name']]);
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

    usort($days, fn(array $a, array $b): int => strcmp($a['day'], $b['day']));
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
