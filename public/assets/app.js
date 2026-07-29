(function () {
    const basePath = window.STEMPELUHR?.basePath || '';
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

    function secondsToTime(seconds) {
        seconds = Math.max(0, Number.parseInt(seconds, 10) || 0);
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const rest = seconds % 60;
        return [hours, minutes, rest].map(number => String(number).padStart(2, '0')).join(':');
    }

    function statusHtml(status, large = false) {
        const classes = {
            WORKING: 'bg-success',
            ON_BREAK: 'bg-warning text-dark',
            NOT_PRESENT: 'bg-secondary',
            HOLIDAY: 'bg-info text-dark',
            VACATION: 'bg-primary',
            SICK: 'bg-danger',
            SCHOOL: 'bg-dark border',
            OTHER: 'bg-light text-dark'
        };
        const className = classes[status?.status] || 'bg-secondary';
        return `<span class="badge ${className}${large ? ' status-big' : ''}">${escapeHtml(status?.label || 'Unbekannt')}</span>`;
    }

    function actionHtml(employeeId, status) {
        const button = (action, text, className) =>
            `<button class="btn btn-lg ${className} tc-action" data-action="${action}" data-employee-id="${employeeId}">${text}</button>`;

        if (status?.status === 'NOT_PRESENT' || status?.status === 'HOLIDAY') {
            return button('work_start', 'Arbeitsbeginn', 'btn-success');
        }
        if (status?.status === 'WORKING') {
            return button('break_start', 'Pause', 'btn-warning') + button('work_end', 'Feierabend', 'btn-danger');
        }
        if (status?.status === 'ON_BREAK') {
            return button('break_end', 'Pause beenden', 'btn-outline-warning') + button('work_end', 'Feierabend', 'btn-danger');
        }
        return '<div class="text-secondary">Heute ist eine Abwesenheit eingetragen.</div>';
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
        const extra = ['WORKING', 'ON_BREAK'].includes(state.status?.status) ? secondsSinceSync(state) : 0;
        return Number(state.totals?.net_seconds || 0) + extra;
    }

    function liveBreakSeconds(state) {
        const extra = state.status?.status === 'ON_BREAK' ? secondsSinceSync(state) : 0;
        return Number(state.totals?.break_seconds || 0) + extra;
    }

    function breaksHtml(state) {
        const breaks = state?.breaks || [];
        if (!breaks.length) {
            return '<div class="text-secondary">Noch keine Pause.</div>';
        }
        const extra = secondsSinceSync(state);
        return breaks.map(item => {
            let duration = Number(item.duration_seconds || 0);
            if (!item.ended_at && state.status?.status === 'ON_BREAK') {
                duration += extra;
            }
            return `
                <div class="break-row">
                    <div>${localTime(item.started_at)} - ${localTime(item.ended_at)}</div>
                    <strong>${secondsToTime(duration)}</strong>
                </div>
            `;
        }).join('');
    }

    function tickDashboard() {
        const table = document.getElementById('employeeTable');
        if (!table) return;
        dashboardState.forEach((state, employeeId) => {
            const row = table.querySelector(`[data-employee-id="${employeeId}"]`);
            if (!row) return;
            row.querySelector('.today-cell').textContent = secondsToTime(liveNetSeconds(state));
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
                row.querySelector('.status-cell').innerHTML = statusHtml(item.status);
            });
            tickDashboard();
        } catch (_) {}
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
            document.getElementById('meStatus').innerHTML = statusHtml(result.status, true);
            document.getElementById('meActions').innerHTML = actionHtml(root.dataset.employeeId, result.status);
            root.classList.toggle('on-break', result.status.status === 'ON_BREAK');
            tickMe();
        } catch (_) {}
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
            document.getElementById('employeeStatus').innerHTML = statusHtml(result.status, true);
            tickEmployee();
        } catch (_) {}
    }

    document.addEventListener('click', async event => {
        const button = event.target.closest('.tc-action');
        if (!button) return;
        button.disabled = true;
        try {
            await apiPost('/api/action', {action: button.dataset.action});
            await refreshMe();
        } catch (error) {
            alert(error.message);
        } finally {
            button.disabled = false;
        }
    });

    const employeeForm = document.getElementById('formEmployee');
    if (employeeForm) {
        employeeForm.addEventListener('submit', async event => {
            event.preventDefault();
            try {
                await apiPost('/api/employee/create', Object.fromEntries(new FormData(employeeForm).entries()));
                location.reload();
            } catch (error) {
                alert(error.message);
            }
        });
    }

    const absenceForm = document.getElementById('formAbsence');
    if (absenceForm) {
        absenceForm.addEventListener('submit', async event => {
            event.preventDefault();
            const data = Object.fromEntries(new FormData(absenceForm).entries());
            data.employeeId = absenceForm.dataset.employeeId;
            try {
                await apiPost('/api/absence/create', data);
                location.reload();
            } catch (error) {
                alert(error.message);
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
            try {
                await apiPost('/api/absence/update', Object.fromEntries(new FormData(absenceEditForm).entries()));
                location.reload();
            } catch (error) {
                alert(error.message);
            }
        });
    }

    const weekReportForm = document.getElementById('weekReportForm');
    const selectAllEmployees = document.getElementById('selectAllEmployees');
    const reportCheckboxes = Array.from(document.querySelectorAll('.report-employee-checkbox'));

    function updateReportSelection() {
        if (!selectAllEmployees) return;
        const selected = reportCheckboxes.filter(box => box.checked).length;
        selectAllEmployees.checked = selected === reportCheckboxes.length && reportCheckboxes.length > 0;
        selectAllEmployees.indeterminate = selected > 0 && selected < reportCheckboxes.length;
    }

    if (selectAllEmployees) {
        selectAllEmployees.addEventListener('change', () => {
            reportCheckboxes.forEach(box => {
                box.checked = selectAllEmployees.checked;
            });
            updateReportSelection();
        });
        reportCheckboxes.forEach(box => box.addEventListener('change', updateReportSelection));
    }

    if (weekReportForm) {
        weekReportForm.addEventListener('submit', event => {
            if (!reportCheckboxes.some(box => box.checked)) {
                event.preventDefault();
                alert('Bitte mindestens einen Mitarbeiter auswählen.');
            }
        });
    }

    document.addEventListener('click', async event => {
        const button = event.target.closest('.absence-delete');
        if (!button) return;
        if (!confirm('Abwesenheit wirklich löschen?')) return;

        button.disabled = true;
        try {
            await apiPost('/api/absence/delete', {absenceId: button.dataset.absenceId});
            location.reload();
        } catch (error) {
            alert(error.message);
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
