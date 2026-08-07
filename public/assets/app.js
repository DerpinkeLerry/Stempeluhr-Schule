(function () {
    'use strict';

    const basePath = window.STEMPELUHR?.basePath || '';

    // Bootstrap modals should be direct children of <body>. The page entrance
    // animation creates a temporary stacking context; keeping dialogs inside it
    // can otherwise place the backdrop above the dialog and block all clicks.
    document.querySelectorAll('.modal').forEach(modal => {
        if (modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
    });

    const dashboardState = new Map();
    let meState = null;
    let employeeState = null;

    function endpoint(path) {
        return basePath + path;
    }

    async function apiPost(path, data) {
        const form = new FormData();
        Object.entries(data).forEach(([key, value]) => form.append(key, value));
        const response = await fetch(endpoint(path), {
            method: 'POST',
            body: form,
            headers: {'X-CSRF-Token': window.STEMPELUHR?.csrf || ''}
        });
        const result = await response.json().catch(() => null);
        if (!result?.ok) {
            throw new Error(result?.error || 'Fehler beim Speichern');
        }
        return result;
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function normalizeSearch(value) {
        return String(value || '').trim().toLocaleLowerCase('de-DE');
    }

    function secondsToTime(seconds) {
        seconds = Math.max(0, Number.parseInt(seconds, 10) || 0);
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const rest = seconds % 60;
        return [hours, minutes, rest].map(number => String(number).padStart(2, '0')).join(':');
    }

    function signedSecondsToTime(seconds) {
        seconds = Number.parseInt(seconds, 10) || 0;
        const sign = seconds < 0 ? '-' : '';
        return sign + secondsToTime(Math.abs(seconds));
    }

    function notificationIcon(type) {
        const icons = {
            success: '<path d="m5 12 4 4L19 6"/>',
            warning: '<path d="M12 8v5M12 16h.01M10.3 3.7 2.7 17a2 2 0 0 0 1.7 3h15.2a2 2 0 0 0 1.7-3L13.7 3.7a2 2 0 0 0-3.4 0Z"/>',
            danger: '<path d="M7 7l10 10M17 7 7 17M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>',
            info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>'
        };
        return `<svg viewBox="0 0 24 24" aria-hidden="true">${icons[type] || icons.info}</svg>`;
    }

    function attachNotificationCountdown(element, duration, dismiss) {
        const progress = element.querySelector('.notification-progress > span');
        if (progress) {
            progress.style.animationDuration = `${duration}ms`;
        }

        let remaining = duration;
        let startedAt = Date.now();
        let timer = 0;
        let stopped = false;
        const pauseReasons = new Set();

        const finish = () => {
            if (stopped) return;
            stopped = true;
            window.clearTimeout(timer);
            dismiss();
        };
        const schedule = () => {
            if (stopped || pauseReasons.size > 0) return;
            if (remaining <= 0) {
                finish();
                return;
            }
            startedAt = Date.now();
            timer = window.setTimeout(finish, remaining);
            if (progress) progress.style.animationPlayState = 'running';
        };
        const pause = reason => {
            if (stopped || pauseReasons.has(reason)) return;
            if (pauseReasons.size === 0) {
                window.clearTimeout(timer);
                remaining = Math.max(0, remaining - (Date.now() - startedAt));
                if (progress) progress.style.animationPlayState = 'paused';
            }
            pauseReasons.add(reason);
        };
        const resume = reason => {
            pauseReasons.delete(reason);
            schedule();
        };
        const stop = () => {
            stopped = true;
            window.clearTimeout(timer);
        };

        schedule();
        element.addEventListener('mouseenter', () => pause('hover'));
        element.addEventListener('mouseleave', () => resume('hover'));
        element.addEventListener('focusin', () => pause('focus'));
        element.addEventListener('focusout', event => {
            if (!element.contains(event.relatedTarget)) resume('focus');
        });
        element.addEventListener('hidden.bs.toast', stop, {once: true});
        element.addEventListener('closed.bs.alert', stop, {once: true});
    }

    function showToast(message, type = 'info') {
        const container = document.getElementById('appToastContainer');
        if (!container || !window.bootstrap?.Toast) {
            window.alert(message);
            return;
        }

        const safeType = ['success', 'warning', 'danger', 'info'].includes(type) ? type : 'info';
        const titles = {
            success: 'Erfolgreich gespeichert',
            warning: 'Bitte beachten',
            danger: 'Aktion nicht möglich',
            info: 'Information'
        };
        const delays = {success: 5500, info: 6500, warning: 8000, danger: 10000};
        const delay = delays[safeType];
        const toast = document.createElement('div');
        toast.className = `toast app-toast toast-${safeType}`;
        toast.setAttribute('role', safeType === 'danger' ? 'alert' : 'status');
        toast.setAttribute('aria-live', safeType === 'danger' ? 'assertive' : 'polite');
        toast.setAttribute('aria-atomic', 'true');
        toast.innerHTML = `
            <div class="toast-body">
                <span class="toast-icon" aria-hidden="true">${notificationIcon(safeType)}</span>
                <span class="toast-copy">
                    <strong>${titles[safeType]}</strong>
                    <span>${escapeHtml(message)}</span>
                </span>
                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Schließen"></button>
            </div>
            <span class="notification-progress" aria-hidden="true"><span></span></span>
        `;
        container.appendChild(toast);
        const instance = new window.bootstrap.Toast(toast, {autohide: false});
        toast.addEventListener('hidden.bs.toast', () => toast.remove(), {once: true});
        instance.show();
        attachNotificationCountdown(toast, delay, () => instance.hide());
    }

    document.querySelectorAll('.app-flash-notification[data-notification-delay]').forEach(alertElement => {
        const delay = Number.parseInt(alertElement.dataset.notificationDelay || '7000', 10);
        const alertInstance = window.bootstrap?.Alert?.getOrCreateInstance(alertElement);
        if (!alertInstance) return;
        attachNotificationCountdown(alertElement, delay, () => alertInstance.close());
    });

    function queueToastAfterReload(message, type = 'success') {
        try {
            window.sessionStorage.setItem('stempeluhr.notification', JSON.stringify({message, type}));
        } catch (_) {
            // sessionStorage may be unavailable in hardened browser modes.
        }
        window.location.reload();
    }

    try {
        const queuedNotification = window.sessionStorage.getItem('stempeluhr.notification');
        if (queuedNotification) {
            window.sessionStorage.removeItem('stempeluhr.notification');
            const parsed = JSON.parse(queuedNotification);
            if (parsed?.message) showToast(parsed.message, parsed.type || 'success');
        }
    } catch (_) {
        // Ignore invalid or unavailable session storage.
    }

    function statusHtml(status, large = false) {
        const classes = {
            WORKING: 'status-working',
            ON_BREAK: 'status-break',
            NOT_PRESENT: 'status-away',
            HOLIDAY: 'status-holiday',
            VACATION: 'status-vacation',
            SICK: 'status-sick',
            SCHOOL: 'status-school',
            OTHER: 'status-other',
            INACTIVE: 'status-away'
        };
        const className = status?.stale_session ? 'status-stale' : (classes[status?.status] || 'status-away');
        return `<span class="status-badge ${className}${large ? ' status-big' : ''}">${escapeHtml(status?.label || 'Unbekannt')}</span>`;
    }

    function actionIcon(action) {
        const icons = {
            work_start: '<path d="m5 12 4 4L19 6"/>',
            break_start: '<path d="M7 8h8v8a4 4 0 0 1-4 4H9a4 4 0 0 1-4-4V8h2ZM15 10h2a3 3 0 0 1 0 6h-2M5 4h10"/>',
            break_end: '<path d="M12 5v14M5 12h14"/>',
            work_end: '<path d="M6 6l12 12M18 6 6 18"/>'
        };
        return `<svg viewBox="0 0 24 24" aria-hidden="true">${icons[action] || icons.work_start}</svg>`;
    }

    function actionButton(employeeId, action, text, className, disabled = false) {
        return `<button class="btn btn-lg ${className} tc-action" data-action="${action}" data-employee-id="${employeeId}"${disabled ? ' disabled' : ''}>${actionIcon(action)}<span>${escapeHtml(text)}</span></button>`;
    }

    function actionHtml(employeeId, status) {
        const startableStatuses = ['NOT_PRESENT', 'HOLIDAY', 'VACATION', 'SICK', 'SCHOOL', 'OTHER'];
        if (startableStatuses.includes(status?.status)) {
            if (status?.work_start_allowed === false) {
                const availableAt = status?.work_start_available_at || '07:30';
                return actionButton(employeeId, 'work_start', `Arbeitsbeginn ab ${availableAt} Uhr`, 'btn-outline-success', true);
            }
            const text = ['VACATION', 'SICK', 'SCHOOL', 'OTHER'].includes(status?.status)
                ? 'Trotz Abwesenheit einstempeln'
                : 'Arbeitsbeginn';
            return actionButton(employeeId, 'work_start', text, 'btn-success');
        }
        if (status?.status === 'WORKING') {
            if (status?.stale_session) {
                return actionButton(employeeId, 'work_end', 'Vergessenen Feierabend korrigieren', 'btn-danger');
            }
            return actionButton(employeeId, 'break_start', 'Pause starten', 'btn-warning')
                + actionButton(employeeId, 'work_end', 'Feierabend', 'btn-danger');
        }
        if (status?.status === 'ON_BREAK') {
            return actionButton(employeeId, 'break_end', 'Pause beenden', 'btn-outline-warning')
                + actionButton(employeeId, 'work_end', 'Feierabend', 'btn-danger');
        }
        return '<div class="clock-hint">Diese Aktion ist derzeit nicht verfügbar.</div>';
    }

    function localTime(utc) {
        if (!utc) return 'läuft';
        const date = new Date(utc.replace(' ', 'T') + 'Z');
        return date.toLocaleTimeString('de-DE', {hour: '2-digit', minute: '2-digit'});
    }

    function secondsSinceSync(state) {
        return Math.max(0, Math.floor((Date.now() - state.syncedAt) / 1000));
    }

    function liveNetSeconds(state) {
        const extra = state.status?.status === 'WORKING' && !state.status?.stale_session ? secondsSinceSync(state) : 0;
        return Number(state.totals?.net_seconds || 0) + extra;
    }

    function liveBreakSeconds(state) {
        const extra = state.status?.status === 'ON_BREAK' ? secondsSinceSync(state) : 0;
        return Number(state.totals?.break_seconds || 0) + extra;
    }

    function liveBreakRemainingSeconds(state) {
        const allowance = Number(state.totals?.break_allowance_seconds ?? 1800);
        return allowance - liveBreakSeconds(state);
    }

    function breaksHtml(state) {
        const breaks = state?.breaks || [];
        if (!breaks.length) {
            return `
                <div class="empty-panel">
                    <span class="empty-panel-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg></span>
                    <strong>Noch keine Pause</strong>
                    <p>Gestartete Pausen werden hier automatisch angezeigt.</p>
                </div>
            `;
        }
        const extra = secondsSinceSync(state);
        return breaks.map((item, index) => {
            let duration = Number(item.duration_seconds || 0);
            if (!item.ended_at && state.status?.status === 'ON_BREAK') {
                duration += extra;
            }
            return `
                <div class="break-row">
                    <span class="break-number">${index + 1}</span>
                    <div class="break-time">
                        <strong>${localTime(item.started_at)} – ${localTime(item.ended_at)}</strong>
                        <small>Pause</small>
                    </div>
                    <strong class="break-duration">${secondsToTime(duration)}</strong>
                </div>
            `;
        }).join('');
    }

    function setButtonLoading(button, loading) {
        if (!button) return;
        button.disabled = loading;
        button.classList.toggle('is-loading', loading);
    }

    function applyEmployeeTableFilters() {
        const table = document.getElementById('employeeTable');
        if (!table) return;

        const rows = Array.from(table.querySelectorAll('tbody tr[data-employee-id]'));
        const employeeSearch = document.getElementById('employeeSearch');
        const showInactiveEmployees = document.getElementById('showInactiveEmployees');
        const empty = document.getElementById('employeeSearchEmpty');
        if (!rows.length) {
            if (empty) empty.hidden = true;
            return;
        }

        const query = normalizeSearch(employeeSearch?.value || '');
        const includeInactive = Boolean(showInactiveEmployees?.checked);
        let visible = 0;
        rows.forEach(row => {
            const matchesEmployment = includeInactive || row.dataset.active === '1';
            const matchesSearch = !query || normalizeSearch(row.dataset.search).includes(query);
            const matches = matchesEmployment && matchesSearch;
            row.hidden = !matches;
            if (matches) visible++;
        });

        if (empty) {
            empty.hidden = visible !== 0;
            if (visible === 0) {
                empty.textContent = query
                    ? 'Keine passenden Mitarbeiter gefunden.'
                    : includeInactive
                        ? 'Keine Mitarbeiter vorhanden.'
                        : 'Keine aktiven Mitarbeiter vorhanden.';
            }
        }
    }

    function tickDashboard() {
        const table = document.getElementById('employeeTable');
        if (!table) return;
        dashboardState.forEach((state, employeeId) => {
            const row = table.querySelector(`[data-employee-id="${employeeId}"]`);
            if (!row) return;
            const cell = row.querySelector('.today-cell');
            if (cell) cell.textContent = secondsToTime(liveNetSeconds(state));
        });
    }

    async function refreshDashboard() {
        const table = document.getElementById('employeeTable');
        if (!table) return;
        try {
            const response = await fetch(endpoint('/api/status'), {cache: 'no-store'});
            const result = await response.json();
            if (!result?.ok) return;

            const tableBody = table.tBodies[0];
            result.items.forEach(item => {
                const state = {...item, syncedAt: Date.now()};
                dashboardState.set(item.employee.id, state);
                const row = table.querySelector(`[data-employee-id="${item.employee.id}"]`);
                if (!row) return;
                row.dataset.active = Number(item.employee.active) === 1 ? '1' : '0';
                const statusCell = row.querySelector('.status-cell');
                if (statusCell) statusCell.innerHTML = statusHtml(item.status);
                if (tableBody) tableBody.appendChild(row);
            });
            applyEmployeeTableFilters();
            tickDashboard();
        } catch (_) {
            // Die vorhandenen Werte bleiben sichtbar, wenn ein kurzer Netzfehler auftritt.
        }
    }

    function tickMe() {
        if (!meState) return;
        const total = document.getElementById('meTotals');
        const breakTotal = document.getElementById('meBreakTotal');
        const breakRemaining = document.getElementById('meBreakRemaining');
        const breaks = document.getElementById('meBreaks');
        if (total) total.textContent = secondsToTime(liveNetSeconds(meState));
        if (breakTotal) breakTotal.textContent = secondsToTime(liveBreakSeconds(meState));
        if (breakRemaining) breakRemaining.textContent = signedSecondsToTime(liveBreakRemainingSeconds(meState));
        if (breaks) breaks.innerHTML = breaksHtml(meState);
    }

    async function refreshMe() {
        const root = document.getElementById('meRoot');
        if (!root) return;
        try {
            const response = await fetch(endpoint('/api/status?employeeId=' + root.dataset.employeeId), {cache: 'no-store'});
            const result = await response.json();
            if (!result?.ok) return;

            meState = {...result, syncedAt: Date.now()};
            const status = document.getElementById('meStatus');
            const actions = document.getElementById('meActions');
            const breakRest = document.getElementById('meBreakRest');
            const isOnBreak = result.status.status === 'ON_BREAK';
            if (status) status.innerHTML = statusHtml(result.status, true);
            if (actions) actions.innerHTML = actionHtml(root.dataset.employeeId, result.status);
            if (breakRest) breakRest.setAttribute('aria-hidden', isOnBreak ? 'false' : 'true');
            root.classList.toggle('on-break', isOnBreak);
            document.body.classList.toggle('employee-on-break', isOnBreak);
            tickMe();
        } catch (_) {
            // Bestehende Anzeige beibehalten.
        }
    }

    function tickEmployee() {
        if (!employeeState) return;
        const total = document.getElementById('employeeToday');
        if (total) total.textContent = secondsToTime(liveNetSeconds(employeeState));
    }

    async function refreshEmployee() {
        const root = document.getElementById('employeeRoot');
        if (!root) return;
        try {
            const response = await fetch(endpoint('/api/status?employeeId=' + root.dataset.employeeId), {cache: 'no-store'});
            const result = await response.json();
            if (!result?.ok) return;

            employeeState = {...result, syncedAt: Date.now()};
            const status = document.getElementById('employeeStatus');
            if (status) status.innerHTML = statusHtml(result.status, true);
            tickEmployee();
        } catch (_) {
            // Bestehende Anzeige beibehalten.
        }
    }

    document.addEventListener('click', async event => {
        const button = event.target.closest('.tc-action');
        if (!button) return;
        setButtonLoading(button, true);
        try {
            const result = await apiPost('/api/action', {action: button.dataset.action});
            await refreshMe();
            if (result.warning) {
                showToast(result.warning, 'warning');
            } else if (result.absence_overridden) {
                showToast('Arbeitszeit wurde gestartet. Die Abwesenheit für heute wurde entfernt.', 'success');
            } else {
                const messages = {
                    work_start: 'Arbeitszeit wurde gestartet.',
                    work_end: 'Arbeitszeit wurde beendet.',
                    break_start: 'Pause wurde gestartet.',
                    break_end: 'Pause wurde beendet.'
                };
                showToast(messages[button.dataset.action] || 'Änderung wurde gespeichert.', 'success');
            }
        } catch (error) {
            showToast(error.message, 'danger');
        } finally {
            setButtonLoading(button, false);
        }
    });

    function formatScheduleMinutes(totalMinutes) {
        const safeMinutes = Math.max(0, Math.round(totalMinutes || 0));
        const hours = Math.floor(safeMinutes / 60);
        const minutes = safeMinutes % 60;
        return `${hours}:${String(minutes).padStart(2, '0')} Std.`;
    }

    function initializeSchedulePlanner(planner) {
        const total = planner.querySelector('[data-schedule-total]')
            || planner.closest('.employee-settings-card')?.querySelector('[data-schedule-total]');
        const dayCards = Array.from(planner.querySelectorAll('[data-schedule-day]'));

        const update = () => {
            let totalMinutes = 0;
            dayCards.forEach(card => {
                const toggle = card.querySelector('[data-schedule-toggle]');
                const input = card.querySelector('[data-schedule-hours]');
                const status = card.querySelector('.schedule-day-card-head span');
                if (!toggle || !input) return;

                const enabled = toggle.checked;
                input.disabled = !enabled;
                card.classList.toggle('is-off', !enabled);
                if (status) status.textContent = enabled ? 'Arbeitstag' : 'Frei';
                if (enabled) {
                    totalMinutes += Math.max(0, Math.round((Number.parseFloat(input.value) || 0) * 60));
                }
            });
            if (total) total.textContent = formatScheduleMinutes(totalMinutes);
        };

        dayCards.forEach(card => {
            const toggle = card.querySelector('[data-schedule-toggle]');
            const input = card.querySelector('[data-schedule-hours]');
            if (!toggle || !input) return;

            if ((Number.parseFloat(input.value) || 0) > 0) {
                input.dataset.lastHours = input.value;
            }

            toggle.addEventListener('change', () => {
                if (!toggle.checked) {
                    const current = Number.parseFloat(input.value) || 0;
                    if (current > 0) input.dataset.lastHours = input.value;
                } else if ((Number.parseFloat(input.value) || 0) <= 0) {
                    input.value = input.dataset.lastHours || input.dataset.defaultHours || '8.5';
                }
                update();
            });

            input.addEventListener('input', update);
            input.addEventListener('change', () => {
                const current = Number.parseFloat(input.value) || 0;
                if (current <= 0) {
                    toggle.checked = false;
                } else {
                    input.dataset.lastHours = input.value;
                }
                update();
            });
        });

        update();
    }

    document.querySelectorAll('[data-schedule-planner]').forEach(initializeSchedulePlanner);

    const employeeForm = document.getElementById('formEmployee');
    if (employeeForm) {
        employeeForm.addEventListener('submit', async event => {
            event.preventDefault();
            const submit = employeeForm.querySelector('[type="submit"]');
            setButtonLoading(submit, true);
            try {
                await apiPost('/api/employee/create', Object.fromEntries(new FormData(employeeForm).entries()));
                location.reload();
            } catch (error) {
                showToast(error.message, 'danger');
                setButtonLoading(submit, false);
            }
        });
    }

    const employeeEditForm = document.getElementById('formEmployeeEdit');
    const deleteEmployeeButton = document.getElementById('deleteEmployeeButton');
    const employeeDeleteHelp = document.getElementById('employeeDeleteHelp');
    const employeeEditTitle = document.getElementById('employeeEditTitle');

    document.addEventListener('click', event => {
        const button = event.target.closest('.employee-edit');
        if (!button || !employeeEditForm) return;

        employeeEditForm.elements.employeeId.value = button.dataset.employeeId || '';
        employeeEditForm.elements.name.value = button.dataset.name || '';
        employeeEditForm.elements.email.value = button.dataset.email || '';
        employeeEditForm.elements.role.value = button.dataset.role || 'employee';
        employeeEditForm.elements.timezone.value = button.dataset.timezone || 'Europe/Berlin';
        employeeEditForm.elements.personnel_number.value = button.dataset.personnelNumber || '';
        employeeEditForm.elements.department.value = button.dataset.department || '';
        employeeEditForm.elements.phone.value = button.dataset.phone || '';
        employeeEditForm.elements.is_trainee.checked = button.dataset.isTrainee === '1';
        employeeEditForm.elements.active.checked = button.dataset.active === '1';
        employeeEditForm.elements.password.value = '';

        const isOwnAccount = button.dataset.employeeId === employeeEditForm.dataset.currentAdminId;
        if (deleteEmployeeButton) {
            deleteEmployeeButton.disabled = isOwnAccount;
            deleteEmployeeButton.dataset.employeeName = button.dataset.name || 'diesen Mitarbeiter';
        }
        if (employeeDeleteHelp) {
            employeeDeleteHelp.textContent = isOwnAccount
                ? 'Das aktuell angemeldete Admin-Konto kann nicht deaktiviert werden.'
                : 'Alle Arbeitszeiten und Abwesenheiten bleiben erhalten.';
        }
        if (employeeEditTitle) {
            employeeEditTitle.textContent = button.dataset.name
                ? `${button.dataset.name} bearbeiten`
                : 'Mitarbeiter bearbeiten';
        }
    });

    if (employeeEditForm) {
        employeeEditForm.addEventListener('submit', async event => {
            event.preventDefault();
            const submit = employeeEditForm.querySelector('[type="submit"]');
            setButtonLoading(submit, true);
            try {
                await apiPost('/api/employee/update', Object.fromEntries(new FormData(employeeEditForm).entries()));
                location.reload();
            } catch (error) {
                showToast(error.message, 'danger');
                setButtonLoading(submit, false);
            }
        });
    }

    if (deleteEmployeeButton && employeeEditForm) {
        deleteEmployeeButton.addEventListener('click', async () => {
            const employeeId = employeeEditForm.elements.employeeId.value;
            const employeeName = deleteEmployeeButton.dataset.employeeName || 'diesen Mitarbeiter';
            if (!employeeId || deleteEmployeeButton.disabled) return;
            if (!window.confirm(`${employeeName} wirklich deaktivieren? Die gesamte Historie bleibt erhalten.`)) return;

            setButtonLoading(deleteEmployeeButton, true);
            try {
                await apiPost('/api/employee/delete', {employeeId});
                location.reload();
            } catch (error) {
                showToast(error.message, 'danger');
                setButtonLoading(deleteEmployeeButton, false);
            }
        });
    }

    const scheduleForm = document.getElementById('formSchedule');
    if (scheduleForm) {
        scheduleForm.addEventListener('submit', async event => {
            event.preventDefault();
            const submit = scheduleForm.querySelector('[type="submit"]');
            const data = Object.fromEntries(new FormData(scheduleForm).entries());
            data.employeeId = scheduleForm.dataset.employeeId;
            setButtonLoading(submit, true);
            try {
                await apiPost('/api/schedule/update', data);
                location.reload();
            } catch (error) {
                showToast(error.message, 'danger');
                setButtonLoading(submit, false);
            }
        });
    }

    const absenceForm = document.getElementById('formAbsence');
    if (absenceForm) {
        absenceForm.addEventListener('submit', async event => {
            event.preventDefault();
            const submit = absenceForm.querySelector('[type="submit"]');
            const data = Object.fromEntries(new FormData(absenceForm).entries());
            data.employeeId = absenceForm.dataset.employeeId;
            setButtonLoading(submit, true);
            try {
                await apiPost('/api/absence/create', data);
                location.reload();
            } catch (error) {
                showToast(error.message, 'danger');
                setButtonLoading(submit, false);
            }
        });
    }

    const absenceEditForm = document.getElementById('formAbsenceEdit');
    document.addEventListener('click', event => {
        const button = event.target.closest('.absence-edit');
        if (!button || !absenceEditForm) return;
        absenceEditForm.elements.absenceId.value = button.dataset.absenceId || '';
        absenceEditForm.elements.type.value = button.dataset.type || 'OTHER';
        absenceEditForm.elements.portion.value = button.dataset.portion || 'FULL';
        absenceEditForm.elements.start_date.value = button.dataset.startDate || '';
        absenceEditForm.elements.end_date.value = button.dataset.endDate || '';
        absenceEditForm.elements.note.value = button.dataset.note || '';
    });

    if (absenceEditForm) {
        absenceEditForm.addEventListener('submit', async event => {
            event.preventDefault();
            const submit = absenceEditForm.querySelector('[type="submit"]');
            setButtonLoading(submit, true);
            try {
                await apiPost('/api/absence/update', Object.fromEntries(new FormData(absenceEditForm).entries()));
                location.reload();
            } catch (error) {
                showToast(error.message, 'danger');
                setButtonLoading(submit, false);
            }
        });
    }

    const vacationForm = document.getElementById('formVacation');
    if (vacationForm) {
        vacationForm.addEventListener('submit', async event => {
            event.preventDefault();
            const submit = vacationForm.querySelector('[type="submit"]');
            const data = Object.fromEntries(new FormData(vacationForm).entries());
            data.employeeId = vacationForm.dataset.employeeId;
            setButtonLoading(submit, true);
            try {
                await apiPost('/api/vacation/update', data);
                const employeeId = vacationForm.dataset.employeeId;
                location.href = endpoint(`/employee?id=${employeeId}&vacation_year=${data.year}`);
            } catch (error) {
                showToast(error.message, 'danger');
                setButtonLoading(submit, false);
            }
        });
    }

    function syncAbsencePortion(form) {
        if (!form?.elements?.type || !form?.elements?.portion) return;
        const vacation = form.elements.type.value === 'VACATION';
        Array.from(form.elements.portion.options).forEach(option => {
            if (option.value !== 'FULL') option.disabled = !vacation;
        });
        if (!vacation) form.elements.portion.value = 'FULL';
    }
    [absenceForm, absenceEditForm].filter(Boolean).forEach(form => {
        form.elements.type.addEventListener('change', () => syncAbsencePortion(form));
        syncAbsencePortion(form);
    });

    const timeReportForm = document.getElementById('timeReportForm');
    const selectAllEmployees = document.getElementById('selectAllEmployees');
    const reportCheckboxes = Array.from(document.querySelectorAll('.report-employee-checkbox'));
    const reportSelectionCount = document.getElementById('reportSelectionCount');
    const reportTypeInputs = Array.from(document.querySelectorAll('input[name="report_type"]'));
    const reportPeriodPanels = Array.from(document.querySelectorAll('[data-report-period-panel]'));
    const reportPeriodEyebrow = document.getElementById('reportPeriodEyebrow');
    const reportPeriodDescription = document.getElementById('reportPeriodDescription');
    const reportPdfSubmitLabel = document.getElementById('reportPdfSubmitLabel');

    const reportTypeCopy = {
        week: {
            eyebrow: 'Wöchentlicher Nachweis',
            description: 'Eine übersichtliche Seite je Mitarbeiter für die gewählte Kalenderwoche.',
            button: 'Wochen-PDF öffnen'
        },
        month: {
            eyebrow: 'Monatlicher Nachweis',
            description: 'Alle Kalendertage des gewählten Monats kompakt auf einer Seite je Mitarbeiter.',
            button: 'Monats-PDF öffnen'
        },
        year: {
            eyebrow: 'Jährlicher Nachweis',
            description: 'Die zwölf Monate mit Arbeitszeit, Pausen und Abwesenheitskennzahlen auf einer Seite je Mitarbeiter.',
            button: 'Jahres-PDF öffnen'
        }
    };

    function selectedReportType() {
        return reportTypeInputs.find(input => input.checked)?.value || 'week';
    }

    function syncReportPeriod() {
        const type = selectedReportType();
        reportPeriodPanels.forEach(panel => {
            const active = panel.dataset.reportPeriodPanel === type;
            panel.hidden = !active;
            panel.querySelectorAll('input, select').forEach(input => {
                input.disabled = !active;
            });
        });
        const copy = reportTypeCopy[type] || reportTypeCopy.week;
        if (reportPeriodEyebrow) reportPeriodEyebrow.textContent = copy.eyebrow;
        if (reportPeriodDescription) reportPeriodDescription.textContent = copy.description;
        if (reportPdfSubmitLabel) reportPdfSubmitLabel.textContent = copy.button;
    }

    function updateReportSelection() {
        if (!selectAllEmployees) return;
        const selected = reportCheckboxes.filter(box => box.checked).length;
        selectAllEmployees.checked = selected === reportCheckboxes.length && reportCheckboxes.length > 0;
        selectAllEmployees.indeterminate = selected > 0 && selected < reportCheckboxes.length;
        if (reportSelectionCount) {
            reportSelectionCount.textContent = `${selected} von ${reportCheckboxes.length} ausgewählt`;
        }
    }

    reportTypeInputs.forEach(input => input.addEventListener('change', syncReportPeriod));
    if (reportTypeInputs.length) syncReportPeriod();

    if (selectAllEmployees) {
        selectAllEmployees.addEventListener('change', () => {
            reportCheckboxes.forEach(box => {
                box.checked = selectAllEmployees.checked;
            });
            updateReportSelection();
        });
        reportCheckboxes.forEach(box => box.addEventListener('change', updateReportSelection));
        updateReportSelection();
    }

    if (timeReportForm) {
        timeReportForm.addEventListener('submit', event => {
            if (!reportCheckboxes.some(box => box.checked)) {
                event.preventDefault();
                showToast('Bitte mindestens einen Mitarbeiter auswählen.', 'warning');
                return;
            }
            const activePanel = reportPeriodPanels.find(panel => !panel.hidden);
            const activeInput = activePanel?.querySelector('input:not([type="radio"]), select');
            if (activeInput && !activeInput.checkValidity()) {
                event.preventDefault();
                activeInput.reportValidity();
            }
        });
    }

    const employeeSearch = document.getElementById('employeeSearch');
    const showInactiveEmployees = document.getElementById('showInactiveEmployees');
    if (employeeSearch || showInactiveEmployees) {
        employeeSearch?.addEventListener('input', applyEmployeeTableFilters);
        showInactiveEmployees?.addEventListener('change', applyEmployeeTableFilters);
        applyEmployeeTableFilters();
    }

    const reportSearch = document.getElementById('reportEmployeeSearch');
    if (reportSearch) {
        const items = Array.from(document.querySelectorAll('.report-employee-item'));
        const empty = document.getElementById('reportSearchEmpty');
        reportSearch.addEventListener('input', () => {
            const query = normalizeSearch(reportSearch.value);
            let visible = 0;
            items.forEach(item => {
                const matches = !query || normalizeSearch(item.dataset.search).includes(query);
                item.hidden = !matches;
                if (matches) visible++;
            });
            if (empty) empty.hidden = visible !== 0;
        });
    }

    document.addEventListener('click', async event => {
        const button = event.target.closest('.absence-delete');
        if (!button) return;
        if (!window.confirm('Abwesenheit wirklich löschen?')) return;

        button.disabled = true;
        try {
            await apiPost('/api/absence/delete', {absenceId: button.dataset.absenceId});
            location.reload();
        } catch (error) {
            showToast(error.message, 'danger');
            button.disabled = false;
        }
    });

    const vacationCalendarRoot = document.getElementById('vacationCalendarRoot');
    if (vacationCalendarRoot) {
        const employeeSearch = document.getElementById('vacationEmployeeSearch');
        const hideFree = document.getElementById('vacationHideFree');
        const yearMatrix = vacationCalendarRoot.querySelector('[data-year-matrix]');
        const yearMonthRows = Array.from(document.querySelectorAll('[data-year-month-row]'));
        const vacationEntries = Array.from(document.querySelectorAll('[data-vacation-entry]'));
        const vacationHitboxes = Array.from(document.querySelectorAll('[data-vacation-hitbox]'));
        const vacationVisualGroups = new Map(
            vacationEntries.map(entry => [entry.dataset.visualGroupId || '', entry])
        );
        const vacationHitboxesByGroup = new Map();
        vacationHitboxes.forEach(hitbox => {
            const groupId = hitbox.dataset.visualGroupId || '';
            if (!vacationHitboxesByGroup.has(groupId)) vacationHitboxesByGroup.set(groupId, []);
            vacationHitboxesByGroup.get(groupId).push(hitbox);
        });
        const employeeLegendItems = Array.from(document.querySelectorAll('[data-employee-legend]'));
        const employeeFilterButtons = Array.from(document.querySelectorAll('[data-employee-filter]'));
        const calendarEmpty = document.getElementById('vacationCalendarEmpty');

        let vacationBarLabelFrame = 0;
        const syncVacationBarLabels = () => {
            if (vacationBarLabelFrame) cancelAnimationFrame(vacationBarLabelFrame);
            vacationBarLabelFrame = requestAnimationFrame(() => {
                vacationBarLabelFrame = 0;
                vacationEntries.forEach(entry => {
                    const label = entry.querySelector('[data-vacation-bar-label]');
                    if (!label) return;

                    const fullName = entry.dataset.fullName || '';
                    const initials = entry.dataset.initials || fullName;
                    label.textContent = fullName;
                    entry.classList.remove('is-initial-label');
                    entry.classList.toggle('is-compact-label', entry.dataset.compactLabel === '1');

                    if (entry.hidden || entry.offsetParent === null) return;

                    // Only half-day bars use initials. Full-day vacation always keeps
                    // the employee name and may shorten it visually with an ellipsis if
                    // a very short range physically cannot contain the complete text.
                    if (entry.classList.contains('is-half')) {
                        label.textContent = initials;
                        entry.classList.add('is-initial-label', 'is-compact-label');
                    }
                });
            });
        };

        const filterVacationRows = () => {
            if (!yearMatrix) return;

            const query = normalizeSearch(employeeSearch?.value || '');
            const onlyWithVacation = Boolean(hideFree?.checked);
            let visibleMonths = 0;
            let visibleEntries = 0;

            vacationEntries.forEach(entry => {
                const matches = !query || normalizeSearch(entry.dataset.search || '').includes(query);
                entry.hidden = !matches;
                entry.classList.toggle('is-muted', false);
                (vacationHitboxesByGroup.get(entry.dataset.visualGroupId || '') || []).forEach(hitbox => {
                    hitbox.hidden = !matches;
                });
                if (matches) visibleEntries++;
            });

            yearMonthRows.forEach(row => {
                const matchingEntries = Array.from(row.querySelectorAll('[data-vacation-entry]')).filter(entry => !entry.hidden).length;
                const hideBecauseSearch = Boolean(query) && matchingEntries === 0;
                const hideBecauseEmpty = onlyWithVacation && matchingEntries === 0;
                row.hidden = hideBecauseSearch || hideBecauseEmpty;
                if (!row.hidden) visibleMonths++;
            });

            employeeLegendItems.forEach(item => {
                const matchesSearch = !query || normalizeSearch(item.dataset.search || '').includes(query);
                const matchesVacation = !onlyWithVacation || item.dataset.hasVacation === '1';
                item.hidden = !(matchesSearch && matchesVacation);
                item.classList.toggle('is-active', Boolean(query) && normalizeSearch(item.dataset.employeeFilter || '') === query);
            });

            if (calendarEmpty) calendarEmpty.hidden = visibleMonths > 0 && (!query || visibleEntries > 0);
            syncVacationBarLabels();
        };

        employeeFilterButtons.forEach(button => {
            button.addEventListener('click', () => {
                if (!employeeSearch) return;
                const value = button.dataset.employeeFilter || '';
                employeeSearch.value = normalizeSearch(employeeSearch.value) === normalizeSearch(value) ? '' : value;
                filterVacationRows();
                employeeSearch.focus({preventScroll: true});
            });
        });

        employeeSearch?.addEventListener('input', filterVacationRows);
        hideFree?.addEventListener('change', filterVacationRows);
        filterVacationRows();

        if (typeof ResizeObserver !== 'undefined' && yearMatrix) {
            const vacationBarResizeObserver = new ResizeObserver(syncVacationBarLabels);
            vacationBarResizeObserver.observe(yearMatrix);
        } else {
            window.addEventListener('resize', syncVacationBarLabels);
        }

        const setVisualGroupHover = (groupId, active) => {
            const group = vacationVisualGroups.get(groupId || '');
            if (group) group.classList.toggle('is-hovered', active);
        };

        vacationHitboxes.forEach(hitbox => {
            const groupId = hitbox.dataset.visualGroupId || '';
            hitbox.addEventListener('mouseenter', () => setVisualGroupHover(groupId, true));
            hitbox.addEventListener('mouseleave', () => setVisualGroupHover(groupId, false));
            hitbox.addEventListener('focus', () => setVisualGroupHover(groupId, true));
            hitbox.addEventListener('blur', () => setVisualGroupHover(groupId, false));
        });

        const syncVacationFormDates = form => {
            if (!form?.elements?.start_date || !form?.elements?.end_date || !form?.elements?.portion) return;
            const start = form.elements.start_date;
            const end = form.elements.end_date;
            const portion = form.elements.portion;
            end.min = start.value || end.min;
            if (start.value && (!end.value || end.value < start.value)) end.value = start.value;
            const halfDay = portion.value !== 'FULL';
            if (halfDay && start.value) end.value = start.value;
            end.readOnly = halfDay;
            end.classList.toggle('is-readonly', halfDay);
        };

        const vacationForms = [
            document.getElementById('vacationCalendarCreateForm'),
            document.getElementById('vacationCalendarEditForm'),
            document.getElementById('vacationRequestForm'),
            document.getElementById('vacationChangeRequestForm')
        ].filter(Boolean);
        vacationForms.forEach(form => {
            form.elements.start_date?.addEventListener('change', () => syncVacationFormDates(form));
            form.elements.end_date?.addEventListener('change', () => syncVacationFormDates(form));
            form.elements.portion?.addEventListener('change', () => syncVacationFormDates(form));
            syncVacationFormDates(form);
        });

        const createForm = document.getElementById('vacationCalendarCreateForm');
        const createModalElement = document.getElementById('vacationCreateModal');
        const selectionSummary = document.getElementById('vacationCalendarSelectionSummary');
        const selectionSummaryText = document.getElementById('vacationCalendarSelectionText');
        const selectionCells = Array.from(vacationCalendarRoot.querySelectorAll('[data-vacation-selection-layer] [data-vacation-select-date]'));
        const selectionHandles = Array.from(vacationCalendarRoot.querySelectorAll('[data-vacation-select-handle-date]'));
        const selectionCellByDate = new Map(selectionCells.map(cell => [cell.dataset.vacationSelectDate || '', cell]));
        let activeCalendarSelection = null;
        let selectedCalendarRange = null;
        let headerHoverCell = null;

        const clearHeaderHover = () => {
            headerHoverCell?.classList.remove('is-header-hovered');
            headerHoverCell = null;
        };

        const setHeaderHoverDate = date => {
            const nextCell = selectionCellByDate.get(date || '') || null;
            const selectableCell = nextCell?.dataset.selectableWorkday === '1' ? nextCell : null;
            if (selectableCell === headerHoverCell) return;
            clearHeaderHover();
            if (!selectableCell || activeCalendarSelection) return;
            selectableCell.classList.add('is-header-hovered');
            headerHoverCell = selectableCell;
        };

        const formatCalendarDate = value => {
            const parts = String(value || '').split('-').map(Number);
            if (parts.length !== 3 || parts.some(part => !Number.isFinite(part))) return value || '';
            return new Intl.DateTimeFormat('de-DE', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric'
            }).format(new Date(Date.UTC(parts[0], parts[1] - 1, parts[2])));
        };

        const selectionBounds = (firstDate, secondDate) => {
            const rawStart = firstDate <= secondDate ? firstDate : secondDate;
            const rawEnd = firstDate <= secondDate ? secondDate : firstDate;
            const workdayCells = selectionCells.filter(cell => {
                const date = cell.dataset.vacationSelectDate || '';
                return cell.dataset.selectableWorkday === '1' && date >= rawStart && date <= rawEnd;
            });

            if (!workdayCells.length) return null;
            return {
                rawStart,
                rawEnd,
                start: workdayCells[0].dataset.vacationSelectDate,
                end: workdayCells[workdayCells.length - 1].dataset.vacationSelectDate,
                workdays: workdayCells.length
            };
        };

        const selectionDateForElement = element => {
            const cell = element?.closest?.('[data-vacation-select-date]');
            if (cell && vacationCalendarRoot.contains(cell)) {
                return {
                    date: cell.dataset.vacationSelectDate || '',
                    selectable: cell.dataset.selectableWorkday === '1',
                    captureElement: cell
                };
            }

            const handle = element?.closest?.('[data-vacation-select-handle-date]');
            if (handle && vacationCalendarRoot.contains(handle)) {
                return {
                    date: handle.dataset.vacationSelectHandleDate || '',
                    selectable: handle.dataset.selectableWorkday === '1',
                    captureElement: handle
                };
            }

            return null;
        };

        const applyCalendarSelectionClasses = (element, date, bounds, anchorDate) => {
            const inRawRange = Boolean(bounds && date >= bounds.rawStart && date <= bounds.rawEnd);
            const isWorkday = element.dataset.selectableWorkday === '1';
            element.classList.toggle('is-range-selected', Boolean(inRawRange && isWorkday));
            element.classList.toggle('is-range-gap', Boolean(inRawRange && !isWorkday));
            element.classList.toggle('is-range-start', Boolean(bounds && date === bounds.start));
            element.classList.toggle('is-range-end', Boolean(bounds && date === bounds.end));
            element.classList.toggle('is-range-anchor', Boolean(inRawRange && date === anchorDate));
        };

        const renderCalendarSelection = (firstDate, secondDate, anchorDate = '') => {
            const bounds = selectionBounds(firstDate, secondDate);
            selectionCells.forEach(cell => {
                applyCalendarSelectionClasses(cell, cell.dataset.vacationSelectDate || '', bounds, anchorDate);
            });
            return bounds;
        };

        const clearCalendarSelection = () => {
            selectionCells.forEach(cell => {
                cell.classList.remove('is-range-selected', 'is-range-gap', 'is-range-start', 'is-range-end', 'is-range-anchor');
            });
            selectionHandles.forEach(handle => {
                handle.classList.remove('is-range-selected', 'is-range-gap', 'is-range-start', 'is-range-end', 'is-range-anchor');
            });
            clearHeaderHover();
            document.body.classList.remove('vacation-range-selecting');
            activeCalendarSelection = null;
        };

        const resolveSelectionCellAtPoint = (clientX, clientY) => {
            const element = document.elementFromPoint(clientX, clientY);
            const directTarget = selectionDateForElement(element);
            if (directTarget?.date) return selectionCellByDate.get(directTarget.date) || null;

            const grid = element?.closest?.('.vacation-year-board-grid');
            if (!grid || !vacationCalendarRoot.contains(grid)) return null;
            const rect = grid.getBoundingClientRect();
            if (clientX < rect.left || clientX > rect.right || rect.width <= 0) return null;
            const dayIndex = Math.max(0, Math.min(30, Math.floor(((clientX - rect.left) / rect.width) * 31)));
            return grid.querySelectorAll('[data-vacation-select-date]')[dayIndex] || null;
        };

        const updateSelectionSummary = bounds => {
            if (selectionSummary && selectionSummaryText) {
                const dayLabel = bounds.workdays === 1 ? '1 markierter Werktag' : `${bounds.workdays} markierte Werktage`;
                selectionSummaryText.textContent = `${formatCalendarDate(bounds.start)} – ${formatCalendarDate(bounds.end)} · ${dayLabel}`;
                selectionSummary.hidden = false;
            }
        };

        const openCreateModalForSelection = bounds => {
            if (!createForm || !createModalElement || !bounds) return;
            createForm.elements.start_date.value = bounds.start;
            createForm.elements.end_date.value = bounds.end;
            createForm.elements.portion.value = 'FULL';
            syncVacationFormDates(createForm);
            selectedCalendarRange = bounds;
            updateSelectionSummary(bounds);

            window.bootstrap?.Modal?.getOrCreateInstance(createModalElement)?.show();
        };

        if (createForm && createModalElement && selectionCells.length) {
            vacationCalendarRoot.addEventListener('pointermove', event => {
                if (activeCalendarSelection) return;
                const handle = event.target.closest?.('[data-vacation-select-handle-date]');
                if (!handle || !vacationCalendarRoot.contains(handle)) {
                    clearHeaderHover();
                    return;
                }
                setHeaderHoverDate(handle.dataset.vacationSelectHandleDate || '');
            });

            vacationCalendarRoot.addEventListener('pointerleave', clearHeaderHover);

            vacationCalendarRoot.addEventListener('focusin', event => {
                const handle = event.target.closest?.('[data-vacation-select-handle-date]');
                if (!handle || !vacationCalendarRoot.contains(handle)) return;
                setHeaderHoverDate(handle.dataset.vacationSelectHandleDate || '');
            });

            vacationCalendarRoot.addEventListener('focusout', event => {
                const nextHandle = event.relatedTarget?.closest?.('[data-vacation-select-handle-date]');
                if (nextHandle && vacationCalendarRoot.contains(nextHandle)) {
                    setHeaderHoverDate(nextHandle.dataset.vacationSelectHandleDate || '');
                    return;
                }
                clearHeaderHover();
            });

            vacationCalendarRoot.addEventListener('pointerdown', event => {
                const target = selectionDateForElement(event.target);
                if (!target || !target.selectable || !target.date || event.button !== 0) return;

                event.preventDefault();
                clearHeaderHover();
                activeCalendarSelection = {
                    pointerId: event.pointerId,
                    startDate: target.date,
                    currentDate: target.date
                };
                selectedCalendarRange = null;
                document.body.classList.add('vacation-range-selecting');
                renderCalendarSelection(target.date, target.date, target.date);
                try {
                    target.captureElement.setPointerCapture(event.pointerId);
                } catch (_) {
                    // Pointer capture is optional; document listeners still keep the drag active.
                }
            });

            vacationCalendarRoot.addEventListener('keydown', event => {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                const target = selectionDateForElement(event.target);
                if (!target || !target.selectable || !target.date) return;
                event.preventDefault();
                const bounds = renderCalendarSelection(target.date, target.date, '');
                if (bounds) openCreateModalForSelection(bounds);
            });

            document.addEventListener('pointermove', event => {
                if (!activeCalendarSelection || event.pointerId !== activeCalendarSelection.pointerId) return;
                if (event.cancelable) event.preventDefault();
                const cell = resolveSelectionCellAtPoint(event.clientX, event.clientY);
                if (!cell) return;
                const date = cell.dataset.vacationSelectDate || '';
                if (!date || date === activeCalendarSelection.currentDate) return;
                activeCalendarSelection.currentDate = date;
                renderCalendarSelection(activeCalendarSelection.startDate, date, date);
            }, {passive: false});

            document.addEventListener('pointerup', event => {
                if (!activeCalendarSelection || event.pointerId !== activeCalendarSelection.pointerId) return;
                const selection = activeCalendarSelection;
                activeCalendarSelection = null;
                document.body.classList.remove('vacation-range-selecting');
                const bounds = renderCalendarSelection(selection.startDate, selection.currentDate, '');
                if (!bounds) {
                    clearCalendarSelection();
                    return;
                }
                openCreateModalForSelection(bounds);
            });

            document.addEventListener('pointercancel', event => {
                if (!activeCalendarSelection || event.pointerId !== activeCalendarSelection.pointerId) return;
                clearCalendarSelection();
            });

            document.addEventListener('keydown', event => {
                if (event.key !== 'Escape' || !activeCalendarSelection) return;
                clearCalendarSelection();
            });

            createModalElement.addEventListener('show.bs.modal', () => {
                if (selectedCalendarRange || !selectionSummary) return;
                selectionSummary.hidden = true;
                if (selectionSummaryText) selectionSummaryText.textContent = '';
            });

            createModalElement.addEventListener('shown.bs.modal', () => {
                createForm.elements.employeeId?.focus({preventScroll: true});
            });

            createModalElement.addEventListener('hidden.bs.modal', () => {
                selectedCalendarRange = null;
                clearCalendarSelection();
                if (selectionSummary) selectionSummary.hidden = true;
                if (selectionSummaryText) selectionSummaryText.textContent = '';
            });

            ['start_date', 'end_date', 'portion'].forEach(fieldName => {
                createForm.elements[fieldName]?.addEventListener('change', () => {
                    if (!selectedCalendarRange) return;
                    const bounds = selectionBounds(
                        createForm.elements.start_date.value,
                        createForm.elements.end_date.value
                    );
                    if (!bounds) {
                        clearCalendarSelection();
                        if (selectionSummaryText) {
                            selectionSummaryText.textContent = 'Der gewählte Zeitraum enthält keinen Werktag.';
                        }
                        return;
                    }
                    selectedCalendarRange = bounds;
                    renderCalendarSelection(bounds.start, bounds.end, '');
                    updateSelectionSummary(bounds);
                });
            });
        }

        if (createForm) {
            createForm.addEventListener('submit', async event => {
                event.preventDefault();
                const submit = createForm.querySelector('[type="submit"]');
                setButtonLoading(submit, true);
                try {
                    await apiPost('/api/absence/create', Object.fromEntries(new FormData(createForm).entries()));
                    queueToastAfterReload('Der Urlaub wurde eingetragen und ist jetzt im Kalender sichtbar.', 'success');
                } catch (error) {
                    showToast(error.message, 'danger');
                    setButtonLoading(submit, false);
                }
            });
        }

        const editForm = document.getElementById('vacationCalendarEditForm');
        const editEmployee = document.getElementById('vacationEditEmployee');
        document.addEventListener('click', event => {
            const button = event.target.closest('.vacation-calendar-edit');
            if (!button || !editForm) return;
            editForm.elements.absenceId.value = button.dataset.absenceId || '';
            editForm.elements.start_date.value = button.dataset.startDate || '';
            editForm.elements.end_date.value = button.dataset.endDate || '';
            editForm.elements.portion.value = button.dataset.portion || 'FULL';
            editForm.elements.note.value = button.dataset.note || '';
            if (editEmployee) editEmployee.textContent = button.dataset.employeeName || '';
            syncVacationFormDates(editForm);
        });

        if (editForm) {
            editForm.addEventListener('submit', async event => {
                event.preventDefault();
                const submit = editForm.querySelector('[type="submit"]');
                setButtonLoading(submit, true);
                try {
                    await apiPost('/api/absence/update', Object.fromEntries(new FormData(editForm).entries()));
                    queueToastAfterReload('Der Urlaub wurde aktualisiert.', 'success');
                } catch (error) {
                    showToast(error.message, 'danger');
                    setButtonLoading(submit, false);
                }
            });
        }

        const deleteVacation = document.getElementById('vacationCalendarDelete');
        if (deleteVacation && editForm) {
            deleteVacation.addEventListener('click', async () => {
                const absenceId = editForm.elements.absenceId.value;
                if (!absenceId || !window.confirm('Diesen Urlaubseintrag wirklich löschen? Der ursprüngliche Antrag bleibt im Archiv erhalten.')) return;
                setButtonLoading(deleteVacation, true);
                try {
                    await apiPost('/api/absence/delete', {absenceId});
                    queueToastAfterReload('Der Urlaubseintrag wurde gelöscht. Antragsdaten bleiben erhalten.', 'success');
                } catch (error) {
                    showToast(error.message, 'danger');
                    setButtonLoading(deleteVacation, false);
                }
            });
        }

        const requestForm = document.getElementById('vacationRequestForm');
        if (requestForm) {
            requestForm.addEventListener('submit', async event => {
                event.preventDefault();
                const submit = requestForm.querySelector('[type="submit"]');
                setButtonLoading(submit, true);
                try {
                    await apiPost('/api/vacation-request/create', Object.fromEntries(new FormData(requestForm).entries()));
                    queueToastAfterReload('Der Urlaubsantrag wurde gespeichert und an die Administration übermittelt.', 'success');
                } catch (error) {
                    showToast(error.message, 'danger');
                    setButtonLoading(submit, false);
                }
            });
        }

        const changeRequestForm = document.getElementById('vacationChangeRequestForm');
        const changeRequestModal = document.getElementById('vacationChangeRequestModal');
        const changeTarget = document.getElementById('vacationChangeTarget');
        const changeFields = document.getElementById('vacationChangeFields');
        const changeCurrentSummary = document.getElementById('vacationChangeCurrentSummary');
        const changeCurrentPeriod = document.getElementById('vacationChangeCurrentPeriod');
        const changeCurrentDays = document.getElementById('vacationChangeCurrentDays');

        const selectedChangeType = () => changeRequestForm?.querySelector('input[name="request_type"]:checked')?.value || 'CHANGE';

        const populateChangeRequestFromVacation = () => {
            if (!changeRequestForm || !changeTarget) return;
            const option = changeTarget.selectedOptions?.[0];
            const hasVacation = Boolean(option?.value);
            if (hasVacation) {
                changeRequestForm.elements.start_date.value = option.dataset.startDate || '';
                changeRequestForm.elements.end_date.value = option.dataset.endDate || '';
                changeRequestForm.elements.portion.value = option.dataset.portion || 'FULL';
                if (changeCurrentPeriod) changeCurrentPeriod.textContent = option.dataset.period || '';
                if (changeCurrentDays) changeCurrentDays.textContent = `${option.dataset.days || '0,0'} Urlaubstage`;
            } else {
                changeRequestForm.elements.start_date.value = '';
                changeRequestForm.elements.end_date.value = '';
                changeRequestForm.elements.portion.value = 'FULL';
                if (changeCurrentPeriod) changeCurrentPeriod.textContent = '';
                if (changeCurrentDays) changeCurrentDays.textContent = '';
            }
            if (changeCurrentSummary) changeCurrentSummary.hidden = !hasVacation;
            syncVacationFormDates(changeRequestForm);
        };

        const syncChangeRequestType = () => {
            if (!changeRequestForm || !changeFields) return;
            const isDelete = selectedChangeType() === 'DELETE';
            changeFields.hidden = isDelete;
            ['start_date', 'end_date'].forEach(name => {
                if (changeRequestForm.elements[name]) changeRequestForm.elements[name].required = !isDelete;
            });
            const submit = changeRequestForm.querySelector('[type="submit"]');
            if (submit) submit.textContent = isDelete ? 'Löschung beantragen' : 'Änderungsantrag senden';
        };

        if (changeRequestForm && changeTarget) {
            changeTarget.addEventListener('change', populateChangeRequestFromVacation);
            changeRequestForm.querySelectorAll('input[name="request_type"]').forEach(input => {
                input.addEventListener('change', syncChangeRequestType);
            });
            populateChangeRequestFromVacation();
            syncChangeRequestType();

            changeRequestModal?.addEventListener('hidden.bs.modal', () => {
                changeRequestForm.reset();
                populateChangeRequestFromVacation();
                syncChangeRequestType();
            });

            changeRequestForm.addEventListener('submit', async event => {
                event.preventDefault();
                const submit = changeRequestForm.querySelector('[type="submit"]');
                setButtonLoading(submit, true);
                try {
                    const requestType = selectedChangeType();
                    await apiPost('/api/vacation-request/change', Object.fromEntries(new FormData(changeRequestForm).entries()));
                    queueToastAfterReload(
                        requestType === 'DELETE'
                            ? 'Der Antrag zur Löschung des Urlaubs wurde gespeichert und an die Administration übermittelt.'
                            : 'Der Änderungsantrag wurde gespeichert und an die Administration übermittelt.',
                        'success'
                    );
                } catch (error) {
                    showToast(error.message, 'danger');
                    setButtonLoading(submit, false);
                }
            });
        }

        const decisionForm = document.getElementById('vacationDecisionForm');
        const decisionModalLabel = document.getElementById('vacationDecisionModalLabel');
        const decisionType = document.getElementById('vacationDecisionType');
        const decisionEmployee = document.getElementById('vacationDecisionEmployee');
        const decisionPeriod = document.getElementById('vacationDecisionPeriod');
        const decisionDays = document.getElementById('vacationDecisionDays');
        const decisionRequestNote = document.getElementById('vacationDecisionRequestNote');
        const decisionApproveText = document.getElementById('vacationDecisionApproveText');
        document.addEventListener('click', event => {
            const button = event.target.closest('.vacation-request-review');
            if (!button || !decisionForm) return;
            const requestType = button.dataset.requestType || 'CREATE';
            decisionForm.elements.requestId.value = button.dataset.requestId || '';
            decisionForm.elements.decision.value = 'APPROVED';
            decisionForm.elements.decision_note.value = '';
            if (decisionModalLabel) {
                decisionModalLabel.textContent = requestType === 'CHANGE'
                    ? 'Urlaubsänderung entscheiden'
                    : requestType === 'DELETE'
                        ? 'Urlaubslöschung entscheiden'
                        : 'Urlaubsantrag entscheiden';
            }
            if (decisionType) {
                decisionType.textContent = button.dataset.requestTypeLabel || 'Neuer Urlaub';
                decisionType.classList.remove('request-type-create', 'request-type-change', 'request-type-delete');
                decisionType.classList.add(
                    requestType === 'CHANGE' ? 'request-type-change' : requestType === 'DELETE' ? 'request-type-delete' : 'request-type-create'
                );
            }
            if (decisionEmployee) decisionEmployee.textContent = button.dataset.employeeName || '';
            if (decisionPeriod) decisionPeriod.textContent = button.dataset.period || '';
            if (decisionDays) {
                decisionDays.textContent = requestType === 'CHANGE'
                    ? `Neu ${button.dataset.days || '0,0'} Urlaubstage · bisher ${button.dataset.originalDays || '0,0'}`
                    : requestType === 'DELETE'
                        ? `${button.dataset.originalDays || '0,0'} Urlaubstage werden entfernt`
                        : `${button.dataset.days || '0,0'} Urlaubstage`;
            }
            if (decisionApproveText) {
                decisionApproveText.textContent = requestType === 'CHANGE'
                    ? 'Der bestehende Urlaub wird sofort auf den neuen Zeitraum verschoben.'
                    : requestType === 'DELETE'
                        ? 'Der bestehende Urlaub wird sofort aus dem Kalender entfernt.'
                        : 'Der Urlaub wird sofort im Kalender eingetragen.';
            }
            if (decisionRequestNote) {
                const note = button.dataset.note || '';
                decisionRequestNote.textContent = note ? `Hinweis: ${note}` : '';
                decisionRequestNote.hidden = !note;
            }
        });

        if (decisionForm) {
            decisionForm.addEventListener('submit', async event => {
                event.preventDefault();
                const submit = decisionForm.querySelector('[type="submit"]');
                const data = Object.fromEntries(new FormData(decisionForm).entries());
                setButtonLoading(submit, true);
                try {
                    const result = await apiPost('/api/vacation-request/decision', data);
                    const approved = result.status === 'APPROVED';
                    const approvedMessage = result.action === 'changed'
                        ? `Der Urlaub von ${result.employee_name} wurde wie beantragt geändert.`
                        : result.action === 'deleted'
                            ? `Der Urlaub von ${result.employee_name} wurde wie beantragt gelöscht.`
                            : `Der Antrag von ${result.employee_name} wurde genehmigt und im Kalender eingetragen.`;
                    queueToastAfterReload(
                        approved
                            ? approvedMessage
                            : `Der Antrag von ${result.employee_name} wurde abgelehnt und archiviert.`,
                        approved ? 'success' : 'info'
                    );
                } catch (error) {
                    showToast(error.message, 'danger');
                    setButtonLoading(submit, false);
                }
            });
        }
    }

    if (document.getElementById('employeeTable')) {
        refreshDashboard();
        setInterval(tickDashboard, 1000);
        setInterval(refreshDashboard, 10000);
    }
    if (document.getElementById('meRoot')) {
        const meRoot = document.getElementById('meRoot');
        document.body.classList.toggle('employee-on-break', meRoot?.classList.contains('on-break'));
        refreshMe();
        setInterval(tickMe, 1000);
        setInterval(refreshMe, 10000);
    }
    if (document.getElementById('employeeRoot')) {
        refreshEmployee();
        setInterval(tickEmployee, 1000);
        setInterval(refreshEmployee, 10000);
    }
})();
