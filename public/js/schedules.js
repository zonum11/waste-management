/* schedules.js - screens to view and manage collection schedules. */

const WEEK_DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

/* resident */

async function initResidentSchedules() {
    const zoneFilter = document.getElementById('zone-filter');
    const tbody = document.getElementById('schedule-body');
    const alertBox = document.getElementById('schedule-alert');

    async function load() {
        tbody.replaceChildren(messageRow(3, 'Loading schedule...'));
        alertBox.classList.add('d-none');
        try {
            const query = zoneFilter.value ? `?zone_id=${encodeURIComponent(zoneFilter.value)}` : '';
            const data = await apiRequest(`schedules${query}`);
            tbody.replaceChildren();
            if (data.schedules.length === 0) {
                tbody.appendChild(messageRow(3, 'No collection schedule found.'));
                return;
            }
            data.schedules.forEach((schedule) => {
                tbody.appendChild(el('tr', {}, [
                    el('td', { class: 'fw-semibold', text: schedule.collect_day }),
                    el('td', { class: 'text-nowrap', text: formatTime(schedule.collect_time) }),
                    el('td', {}, [zoneBadges(schedule.zones)]),
                ]));
            });
        } catch (error) {
            tbody.replaceChildren();
            showAlert(alertBox, error.message);
        }
    }

    try {
        const data = await apiRequest('zones');
        data.zones.forEach((zone) => {
            zoneFilter.appendChild(el('option', { text: zone.zone_name, attrs: { value: String(zone.zone_id) } }));
        });
    } catch (error) {
        showAlert(alertBox, error.message);
    }

    zoneFilter.addEventListener('change', load);
    load();
}

/* admin */

async function initAdminSchedules() {
    const tbody = document.getElementById('schedule-body');
    const alertBox = document.getElementById('schedule-alert');
    const form = document.getElementById('schedule-form');
    const formAlert = document.getElementById('schedule-form-alert');
    const formTitle = document.getElementById('schedule-modal-title');
    const saveButton = document.getElementById('schedule-save-btn');
    const zoneBox = document.getElementById('zone-checkboxes');
    const modal = new bootstrap.Modal(document.getElementById('schedule-modal'));
    const deleteModal = new bootstrap.Modal(document.getElementById('delete-modal'));
    const deleteText = document.getElementById('delete-modal-text');
    const deleteButton = document.getElementById('delete-confirm-btn');

    let schedules = [];
    let editingId = null;      // null means adding a new schedule
    let deletingId = null;

    // Fill the day dropdown and zone checkboxes once.
    WEEK_DAYS.forEach((day) => {
        form.elements.collect_day.appendChild(el('option', { text: day, attrs: { value: day } }));
    });
    try {
        const data = await apiRequest('zones');
        data.zones.forEach((zone) => {
            const id = `zone-${zone.zone_id}`;
            zoneBox.appendChild(el('div', { class: 'form-check' }, [
                el('input', { class: 'form-check-input', attrs: { type: 'checkbox', id, value: String(zone.zone_id), name: 'zone' } }),
                el('label', { class: 'form-check-label', text: zone.zone_name, attrs: { for: id } }),
            ]));
        });
    } catch (error) {
        showAlert(alertBox, error.message);
    }

    async function load() {
        tbody.replaceChildren(messageRow(5, 'Loading schedules...'));
        try {
            schedules = (await apiRequest('schedules')).schedules;
            render();
        } catch (error) {
            tbody.replaceChildren();
            showAlert(alertBox, error.message);
        }
    }

    function render() {
        tbody.replaceChildren();
        if (schedules.length === 0) {
            tbody.appendChild(messageRow(5, 'No schedules yet. Use "Add schedule" to create one.'));
            return;
        }
        schedules.forEach((schedule) => {
            const isActive = schedule.status === 'active';
            const editBtn = el('button', { class: 'btn btn-sm btn-outline-secondary', attrs: { type: 'button', title: 'Edit', 'aria-label': `Edit ${schedule.collect_day} schedule` } },
                [el('i', { class: 'bi bi-pencil', attrs: { 'aria-hidden': 'true' } })]);
            const deleteBtn = el('button', { class: 'btn btn-sm btn-outline-danger', attrs: { type: 'button', title: 'Delete', 'aria-label': `Delete ${schedule.collect_day} schedule` } },
                [el('i', { class: 'bi bi-trash', attrs: { 'aria-hidden': 'true' } })]);
            editBtn.addEventListener('click', () => openForm(schedule));
            deleteBtn.addEventListener('click', () => askDelete(schedule));

            tbody.appendChild(el('tr', {}, [
                el('td', { class: 'fw-semibold', text: schedule.collect_day }),
                el('td', { class: 'text-nowrap', text: formatTime(schedule.collect_time) }),
                el('td', {}, [zoneBadges(schedule.zones)]),
                el('td', {}, [el('span', { class: `badge ${isActive ? 'text-bg-success' : 'text-bg-secondary'}`, text: isActive ? 'Active' : 'Inactive' })]),
                el('td', { class: 'text-end text-nowrap' }, [editBtn, document.createTextNode(' '), deleteBtn]),
            ]));
        });
    }

    function openForm(schedule) {
        editingId = schedule ? schedule.sched_id : null;
        clearFormErrors(form, formAlert);
        formTitle.textContent = schedule ? 'Edit schedule' : 'Add schedule';

        form.elements.collect_day.value = schedule ? schedule.collect_day : 'Monday';
        form.elements.collect_time.value = schedule ? schedule.collect_time : '07:00';
        form.elements.status.value = schedule ? schedule.status : 'active';
        const selected = schedule ? schedule.zones.map((zone) => String(zone.zone_id)) : [];
        zoneBox.querySelectorAll('input[type=checkbox]').forEach((box) => { box.checked = selected.includes(box.value); });
        modal.show();
    }

    async function save(event) {
        event.preventDefault();
        clearFormErrors(form, formAlert);
        setBusy(saveButton, true, 'Saving...');

        const body = {
            collect_day: form.elements.collect_day.value,
            collect_time: form.elements.collect_time.value,
            status: form.elements.status.value,
            zone_ids: Array.from(zoneBox.querySelectorAll('input:checked')).map((box) => Number(box.value)),
        };

        try {
            if (editingId === null) {
                await apiRequest('schedules', { method: 'POST', body });
            } else {
                await apiRequest(`schedules/${editingId}`, { method: 'PUT', body });
            }
            modal.hide();
            showAlert(alertBox, editingId === null ? 'Schedule added.' : 'Schedule updated.', 'success');
            await load();
        } catch (error) {
            showAlert(formAlert, error.message);
            showFieldErrors(form, error.errors);
        }
        setBusy(saveButton, false);
    }

    function askDelete(schedule) {
        deletingId = schedule.sched_id;
        deleteText.textContent = `Delete the ${schedule.collect_day} ${formatTime(schedule.collect_time)} schedule? This cannot be undone.`;
        deleteModal.show();
    }

    async function confirmDelete() {
        setBusy(deleteButton, true, 'Deleting...');
        try {
            await apiRequest(`schedules/${deletingId}`, { method: 'DELETE' });
            deleteModal.hide();
            showAlert(alertBox, 'Schedule deleted.', 'success');
            await load();
        } catch (error) {
            deleteModal.hide();
            showAlert(alertBox, error.message);
        }
        setBusy(deleteButton, false);
    }

    document.getElementById('add-schedule-btn').addEventListener('click', () => openForm(null));
    form.addEventListener('submit', save);
    deleteButton.addEventListener('click', confirmDelete);
    load();
}
