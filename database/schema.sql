CREATE TABLE IF NOT EXISTS employee (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    personnel_number TEXT UNIQUE COLLATE NOCASE,
    legacy_employee_id INTEGER UNIQUE,
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
);

CREATE TABLE IF NOT EXISTS employee_schedule (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL,
    valid_from TEXT NOT NULL,
    valid_to TEXT,
    weekday INTEGER NOT NULL CHECK(weekday BETWEEN 1 AND 7),
    target_minutes INTEGER NOT NULL DEFAULT 0 CHECK(target_minutes BETWEEN 0 AND 1440),
    planned_start TEXT NOT NULL DEFAULT '',
    planned_end TEXT NOT NULL DEFAULT '',
    source TEXT NOT NULL DEFAULT 'web',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(employee_id) REFERENCES employee(id) ON DELETE CASCADE,
    CHECK(valid_to IS NULL OR valid_to >= valid_from),
    UNIQUE(employee_id, valid_from, weekday)
);

CREATE TABLE IF NOT EXISTS vacation_account (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL,
    year INTEGER NOT NULL CHECK(year BETWEEN 1970 AND 2200),
    entitlement_days REAL NOT NULL DEFAULT 0 CHECK(entitlement_days >= 0),
    carryover_days REAL NOT NULL DEFAULT 0,
    adjustment_days REAL NOT NULL DEFAULT 0,
    note TEXT NOT NULL DEFAULT '',
    source TEXT NOT NULL DEFAULT 'web',
    legacy_entitlement_days REAL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(employee_id) REFERENCES employee(id) ON DELETE CASCADE,
    UNIQUE(employee_id, year)
);

CREATE TABLE IF NOT EXISTS work_rule (
    weekday INTEGER PRIMARY KEY CHECK(weekday BETWEEN 1 AND 7),
    earliest_start TEXT NOT NULL DEFAULT '07:30',
    break_bonus_until TEXT NOT NULL DEFAULT '08:00',
    forgotten_end TEXT NOT NULL DEFAULT '17:00',
    base_break_minutes INTEGER NOT NULL DEFAULT 30 CHECK(base_break_minutes BETWEEN 0 AND 1440),
    default_target_minutes INTEGER NOT NULL DEFAULT 0 CHECK(default_target_minutes BETWEEN 0 AND 1440),
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS work_session (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL,
    started_at TEXT NOT NULL,
    ended_at TEXT,
    source TEXT NOT NULL DEFAULT 'web',
    legacy_worktime_id INTEGER UNIQUE,
    note TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(employee_id) REFERENCES employee(id) ON DELETE CASCADE,
    CHECK(ended_at IS NULL OR ended_at >= started_at)
);

CREATE TABLE IF NOT EXISTS break_session (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    work_session_id INTEGER NOT NULL,
    started_at TEXT NOT NULL,
    ended_at TEXT,
    source TEXT NOT NULL DEFAULT 'web',
    legacy_break_id INTEGER UNIQUE,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(work_session_id) REFERENCES work_session(id) ON DELETE CASCADE,
    CHECK(ended_at IS NULL OR ended_at >= started_at)
);

CREATE TABLE IF NOT EXISTS absence (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL,
    type TEXT NOT NULL CHECK(type IN ('VACATION', 'SICK', 'SCHOOL', 'OTHER')),
    portion TEXT NOT NULL DEFAULT 'FULL' CHECK(portion IN ('FULL', 'AM', 'PM')),
    start_date TEXT NOT NULL,
    end_date TEXT NOT NULL,
    note TEXT NOT NULL DEFAULT '',
    source TEXT NOT NULL DEFAULT 'web',
    legacy_worktime_id INTEGER UNIQUE,
    credit_minutes_override INTEGER CHECK(credit_minutes_override IS NULL OR credit_minutes_override >= 0),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(employee_id) REFERENCES employee(id) ON DELETE CASCADE,
    CHECK(end_date >= start_date),
    CHECK(portion = 'FULL' OR start_date = end_date),
    CHECK(portion = 'FULL' OR type = 'VACATION')
);

CREATE TABLE IF NOT EXISTS vacation_request (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL,
    start_date TEXT NOT NULL,
    end_date TEXT NOT NULL,
    portion TEXT NOT NULL DEFAULT 'FULL' CHECK(portion IN ('FULL', 'AM', 'PM')),
    note TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'PENDING' CHECK(status IN ('PENDING', 'APPROVED', 'REJECTED', 'CANCELLED')),
    requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at TEXT,
    decided_by INTEGER,
    decision_note TEXT NOT NULL DEFAULT '',
    absence_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(employee_id) REFERENCES employee(id) ON DELETE RESTRICT,
    FOREIGN KEY(decided_by) REFERENCES employee(id) ON DELETE SET NULL,
    FOREIGN KEY(absence_id) REFERENCES absence(id) ON DELETE SET NULL,
    CHECK(end_date >= start_date),
    CHECK(portion = 'FULL' OR start_date = end_date)
);

CREATE TABLE IF NOT EXISTS public_holiday (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    region TEXT NOT NULL,
    day TEXT NOT NULL,
    name TEXT NOT NULL,
    source TEXT NOT NULL DEFAULT 'system',
    legacy_public_holiday_id INTEGER UNIQUE,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(region, day, name)
);

CREATE TABLE IF NOT EXISTS overtime_event (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    day TEXT NOT NULL,
    note TEXT NOT NULL DEFAULT '',
    credit_minutes INTEGER NOT NULL DEFAULT 0,
    source TEXT NOT NULL DEFAULT 'web',
    legacy_overtime_id INTEGER UNIQUE,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS import_batch (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_system TEXT NOT NULL,
    source_reference TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'RUNNING' CHECK(status IN ('RUNNING', 'SUCCESS', 'FAILED')),
    started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TEXT,
    summary TEXT NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_employee_personnel_number ON employee(personnel_number);
CREATE INDEX IF NOT EXISTS idx_employee_active_name ON employee(active, name);
CREATE INDEX IF NOT EXISTS idx_schedule_employee_date ON employee_schedule(employee_id, valid_from, valid_to, weekday);
CREATE INDEX IF NOT EXISTS idx_vacation_employee_year ON vacation_account(employee_id, year);
CREATE INDEX IF NOT EXISTS idx_work_employee_start ON work_session(employee_id, started_at);
CREATE INDEX IF NOT EXISTS idx_break_work_start ON break_session(work_session_id, started_at);
CREATE INDEX IF NOT EXISTS idx_absence_employee_dates ON absence(employee_id, start_date, end_date);
CREATE INDEX IF NOT EXISTS idx_vacation_request_employee_status ON vacation_request(employee_id, status, start_date, end_date);
CREATE INDEX IF NOT EXISTS idx_vacation_request_status_requested ON vacation_request(status, requested_at DESC);
CREATE INDEX IF NOT EXISTS idx_holiday_region_day ON public_holiday(region, day);
CREATE INDEX IF NOT EXISTS idx_overtime_day ON overtime_event(day);
CREATE UNIQUE INDEX IF NOT EXISTS one_open_work ON work_session(employee_id) WHERE ended_at IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS one_open_break ON break_session(work_session_id) WHERE ended_at IS NULL;
