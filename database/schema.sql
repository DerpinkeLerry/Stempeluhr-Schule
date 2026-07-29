CREATE TABLE IF NOT EXISTS employee (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'employee' CHECK(role IN ('admin', 'employee')),
    timezone TEXT NOT NULL DEFAULT 'Europe/Berlin',
    holiday_region TEXT NOT NULL DEFAULT 'DE-BY-KF',
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS work_session (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL,
    started_at TEXT NOT NULL,
    ended_at TEXT,
    source TEXT NOT NULL DEFAULT 'web',
    FOREIGN KEY(employee_id) REFERENCES employee(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS break_session (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    work_session_id INTEGER NOT NULL,
    started_at TEXT NOT NULL,
    ended_at TEXT,
    FOREIGN KEY(work_session_id) REFERENCES work_session(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS absence (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL,
    type TEXT NOT NULL CHECK(type IN ('VACATION', 'SICK', 'SCHOOL', 'OTHER')),
    start_date TEXT NOT NULL,
    end_date TEXT NOT NULL,
    note TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    FOREIGN KEY(employee_id) REFERENCES employee(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS public_holiday (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    region TEXT NOT NULL,
    day TEXT NOT NULL,
    name TEXT NOT NULL,
    UNIQUE(region, day, name)
);

CREATE INDEX IF NOT EXISTS idx_work_employee_start ON work_session(employee_id, started_at);
CREATE INDEX IF NOT EXISTS idx_break_work_start ON break_session(work_session_id, started_at);
CREATE INDEX IF NOT EXISTS idx_absence_employee_dates ON absence(employee_id, start_date, end_date);
CREATE INDEX IF NOT EXISTS idx_holiday_region_day ON public_holiday(region, day);
CREATE UNIQUE INDEX IF NOT EXISTS one_open_work ON work_session(employee_id) WHERE ended_at IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS one_open_break ON break_session(work_session_id) WHERE ended_at IS NULL;
