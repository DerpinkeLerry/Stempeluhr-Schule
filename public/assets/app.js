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

    function showToast(message, type = 'info') {
        const container = document.getElementById('appToastContainer');
        if (!container || !window.bootstrap?.Toast) {
            window.alert(message);
            return;
        }

        const toast = document.createElement('div');
        const safeType = ['success', 'warning', 'danger', 'info'].includes(type) ? type : 'info';
        toast.className = `toast toast-${safeType}`;
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        toast.setAttribute('aria-atomic', 'true');
        toast.innerHTML = `
            <div class="toast-body">
                <span class="toast-dot" aria-hidden="true"></span>
                <span class="flex-grow-1">${escapeHtml(message)}</span>
                <button type="button" class="btn-close ms-2" data-bs-dismiss="toast" aria-label="Schließen"></button>
            </div>
        `;
        container.appendChild(toast);
        const instance = new window.bootstrap.Toast(toast, {delay: type === 'warning' ? 6500 : 4200});
        toast.addEventListener('hidden.bs.toast', () => toast.remove(), {once: true});
        instance.show();
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
            OTHER: 'status-other'
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
        if (status?.status === 'NOT_PRESENT' || status?.status === 'HOLIDAY') {
            if (status?.work_start_allowed === false) {
                return actionButton(employeeId, 'work_start', 'Arbeitsbeginn ab 07:30 Uhr', 'btn-outline-success', true);
            }
            return actionButton(employeeId, 'work_start', 'Arbeitsbeginn', 'btn-success');
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
        return '<div class="clock-hint">Heute ist eine Abwesenheit eingetragen.</div>';
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

            result.items.forEach(item => {
                const state = {...item, syncedAt: Date.now()};
                dashboardState.set(item.employee.id, state);
                const row = table.querySelector(`[data-employee-id="${item.employee.id}"]`);
                if (!row) return;
                const statusCell = row.querySelector('.status-cell');
                if (statusCell) statusCell.innerHTML = statusHtml(item.status);
            });
            tickDashboard();
        } catch (_) {
            // Die vorhandenen Werte bleiben sichtbar, wenn ein kurzer Netzfehler auftritt.
        }
    }

    function tickMe() {
        if (!meState) return;
        const total = document.getElementById('meTotals');
        const breakTotal = document.getElementById('meBreakTotal');
        const breaks = document.getElementById('meBreaks');
        if (total) total.textContent = secondsToTime(liveNetSeconds(meState));
        if (breakTotal) breakTotal.textContent = secondsToTime(liveBreakSeconds(meState));
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
            if (status) status.innerHTML = statusHtml(result.status, true);
            if (actions) actions.innerHTML = actionHtml(root.dataset.employeeId, result.status);
            root.classList.toggle('on-break', result.status.status === 'ON_BREAK');
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

    const weekReportForm = document.getElementById('weekReportForm');
    const selectAllEmployees = document.getElementById('selectAllEmployees');
    const reportCheckboxes = Array.from(document.querySelectorAll('.report-employee-checkbox'));
    const reportSelectionCount = document.getElementById('reportSelectionCount');

    function updateReportSelection() {
        if (!selectAllEmployees) return;
        const selected = reportCheckboxes.filter(box => box.checked).length;
        selectAllEmployees.checked = selected === reportCheckboxes.length && reportCheckboxes.length > 0;
        selectAllEmployees.indeterminate = selected > 0 && selected < reportCheckboxes.length;
        if (reportSelectionCount) {
            reportSelectionCount.textContent = `${selected} von ${reportCheckboxes.length} ausgewählt`;
        }
    }

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

    if (weekReportForm) {
        weekReportForm.addEventListener('submit', event => {
            if (!reportCheckboxes.some(box => box.checked)) {
                event.preventDefault();
                showToast('Bitte mindestens einen Mitarbeiter auswählen.', 'warning');
            }
        });
    }

    const employeeSearch = document.getElementById('employeeSearch');
    if (employeeSearch) {
        const rows = Array.from(document.querySelectorAll('#employeeTable tbody tr[data-employee-id]'));
        const empty = document.getElementById('employeeSearchEmpty');
        employeeSearch.addEventListener('input', () => {
            const query = normalizeSearch(employeeSearch.value);
            let visible = 0;
            rows.forEach(row => {
                const matches = !query || normalizeSearch(row.dataset.search).includes(query);
                row.hidden = !matches;
                if (matches) visible++;
            });
            if (empty) empty.hidden = visible !== 0;
        });
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

    if (document.getElementById('employeeTable')) {
        refreshDashboard();
        setInterval(tickDashboard, 1000);
        setInterval(refreshDashboard, 10000);
    }
    if (document.getElementById('meRoot')) {
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
