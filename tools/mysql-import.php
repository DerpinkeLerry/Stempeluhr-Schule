<?php
declare(strict_types=1);

return [
    // Die alte MySQL-Datenbank wird vom Importer ausschliesslich gelesen.
    // Am sichersten ist ein MySQL-Benutzer mit SELECT- und SHOW-VIEW-Rechten.
    'mysql' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'worktimekeeping',
        'username' => 'Strumpfhose',
        'password' => 'Kaka',
        'charset' => 'utf8mb4',
        'connect_timeout_seconds' => 10,
    ],

    // Relativ zu dieser Konfigurationsdatei oder als absoluter Pfad.
    'target_sqlite' => '../data/stempeluhr.sqlite',

    'source_timezone' => 'Europe/Berlin',
    'holiday_region' => 'DE-BY-KF',

    // emploee.holidays ist im Altsystem nicht jahresbezogen.
    // Deshalb muss festgelegt werden, fuer welches Jahr der Anspruch gilt.
    'vacation_entitlement_year' => (int)date('Y'),

    // Normalerweise 0. Nur aendern, wenn die Stichproben im Dry-Run exakt
    // um eine oder zwei Stunden verschoben sind.
    'legacy_timestamp_adjustment_seconds' => 0,

    // Optionaler Importbereich. Leere Werte importieren die gesamte Historie.
    'from_date' => '',
    'to_date' => '',

    // Das alte halfholiday-Feld enthaelt kein eigenes AM/PM-Feld.
    // Der Importer leitet es aus Arbeitsbeginn/-ende ab.
    'half_day_split' => '12:00',
    'unknown_half_day_portion' => 'PM',

    // Alte offene Arbeitssitzungen werden standardmaessig mit dem Tagesende
    // geschlossen. Alternativen: 'skip' oder 'open'.
    'open_session_policy' => 'close-default',
    'default_end_by_weekday' => [
        1 => '17:00:00',
        2 => '17:00:00',
        3 => '17:00:00',
        4 => '17:00:00',
        5 => '12:00:00',
        6 => '17:00:00',
        7 => '17:00:00',
    ],
    'max_work_session_hours' => 24,
    'max_break_hours' => 8,

    // Bei gleicher Personalnummer und verschiedenem Namen wird abgebrochen.
    // Alternativen: 'skip' oder 'merge'.
    'employee_name_conflict_policy' => 'fail',

    // Legacy-Stammdaten gewinnen, Login/E-Mail/Rolle bleiben im Ziel erhalten.
    // Alternative: 'preserve-target'.
    'employee_profile_merge_policy' => 'legacy-wins',

    // Ein vorhandener, bereits gepflegter Anspruch wird bei 'fill-empty'
    // nicht ueberschrieben. Der alte Wert landet immer in legacy_entitlement_days.
    'vacation_account_policy' => 'fill-empty',
    'schedule_valid_from' => '1970-01-01',

    // Explizite Zuordnung bei abweichenden Ziel-Personalnummern:
    // '123' => 'M-00123'
    'employee_target_map' => [],
    'skip_source_personnel_numbers' => [],

    'import' => [
        'employees' => true,
        'schedules' => true,
        'vacation_accounts' => true,
        'public_holidays' => true,
        'work_sessions' => true,
        'breaks' => true,
        'absences' => true,
        'overtime' => true,
    ],

    // Die Namen passen zum bekannten alten StempelUhrAdmin-Projekt.
    // Bei abweichenden Namen zuerst --inspect ausfuehren und hier anpassen.
    'tables' => [
        'employees' => 'emploee',
        'worktime' => 'worktime',
        'breaks' => 'break',
        'public_holidays' => 'public_holiday',
        // Leer lassen, damit bekannte Namen automatisch gesucht werden.
        'overtime' => '',
    ],

    // Nur notwendig, falls die echten MySQL-Spalten anders heissen.
    // Beispiel: 'employees' => ['persid' => 'Personalnummer']
    'columns' => [
        'employees' => [],
        'worktime' => [],
        'breaks' => [],
        'public_holidays' => [],
        'overtime' => [],
    ],

    // Einheit einer optionalen alten Ueberstunden-Spalte: 'minutes' oder 'hours'.
    'overtime_value_unit' => 'minutes',
];
