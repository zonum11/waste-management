/* reports.js - screens to create, list and view waste reports. */

const MAX_PHOTO_BYTES = 5 * 1024 * 1024;
const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

/* new report */

async function initNewReport() {
    const form = document.getElementById('report-form');
    const alertBox = document.getElementById('form-alert');
    const button = document.getElementById('submit-btn');
    const typeBox = document.getElementById('waste-type-checkboxes');

    // Load zones and waste types from the API.
    try {
        const [zones, wasteTypes] = await Promise.all([apiRequest('zones'), apiRequest('waste-types')]);
        zones.zones.forEach((zone) => {
            form.elements.zone_id.appendChild(el('option', { text: zone.zone_name, attrs: { value: String(zone.zone_id) } }));
        });
        wasteTypes.waste_types.forEach((type) => {
            const id = `waste-type-${type.type_id}`;
            typeBox.appendChild(el('div', { class: 'form-check' }, [
                el('input', { class: 'form-check-input', attrs: { type: 'checkbox', id, value: String(type.type_id) } }),
                el('label', { class: 'form-check-label', text: type.type_name, attrs: { for: id } }),
            ]));
        });
    } catch (error) {
        showAlert(alertBox, error.message);
        return;
    }

    Object.entries(ISSUE_LABELS).forEach(([value, label]) => {
        form.elements.issue_type.appendChild(el('option', { text: label, attrs: { value } }));
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFormErrors(form, alertBox);

        // Quick browser check; the server checks again because it cannot trust the browser.
        const photo = form.elements.photo.files[0];
        if (photo && (!PHOTO_TYPES.includes(photo.type) || photo.size > MAX_PHOTO_BYTES)) {
            showFieldErrors(form, { photo: 'Photo must be a JPEG, PNG or WebP image of 5 MB or less.' });
            return;
        }

        // FormData can carry the photo file.
        const body = new FormData();
        body.append('zone_id', form.elements.zone_id.value);
        body.append('issue_type', form.elements.issue_type.value);
        body.append('issue_description', form.elements.issue_description.value);
        typeBox.querySelectorAll('input:checked').forEach((box) => body.append('waste_type_ids[]', box.value));
        if (photo) body.append('photo', photo);

        setBusy(button, true, 'Submitting...');
        try {
            const data = await apiRequest('reports', { method: 'POST', body });
            window.location.replace(`report.html?id=${data.report.report_id}&submitted=1`);
        } catch (error) {
            showAlert(alertBox, error.message);
            showFieldErrors(form, error.errors);
            setBusy(button, false);
        }
    });
}

/* report list */

function initReportList(isAdmin) {
    const statusFilter = document.getElementById('status-filter');
    // These exist only on the admin page, so they are checked before use.
    const zoneFilter = document.getElementById('zone-filter');
    const dateFrom = document.getElementById('date-from');
    const dateTo = document.getElementById('date-to');
    const clearButton = document.getElementById('clear-filters');

    const tbody = document.getElementById('report-body');
    const alertBox = document.getElementById('report-alert');
    const pager = document.getElementById('pagination');
    const columns = isAdmin ? 7 : 6;
    let page = 1;

    Object.entries(STATUS_LABELS).forEach(([value, label]) => {
        statusFilter.appendChild(el('option', { text: label, attrs: { value } }));
    });

    // Dashboard links can pre-select a status (?status=pending).
    const startStatus = new URLSearchParams(window.location.search).get('status');
    if (Object.keys(STATUS_LABELS).includes(startStatus)) {
        statusFilter.value = startStatus;
    }

    if (zoneFilter) {
        apiRequest('zones').then((data) => {
            data.zones.forEach((zone) => {
                zoneFilter.appendChild(el('option', { text: zone.zone_name, attrs: { value: String(zone.zone_id) } }));
            });
        }).catch((error) => showAlert(alertBox, error.message));
    }

    // Pressing Enter in the filters must not reload the page.
    const filterForm = document.getElementById('report-filters');
    if (filterForm) {
        filterForm.addEventListener('submit', (event) => event.preventDefault());
    }

    function activeFilters() {
        const filters = {
            status: statusFilter.value,
            zone_id: zoneFilter ? zoneFilter.value : '',
            date_from: dateFrom ? dateFrom.value : '',
            date_to: dateTo ? dateTo.value : '',
        };
        return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));
    }

    async function load() {
        tbody.replaceChildren(messageRow(columns, 'Loading reports...'));
        pager.replaceChildren();
        alertBox.classList.add('d-none');

        const filters = activeFilters();
        const query = new URLSearchParams({ page: String(page), ...filters });

        try {
            const data = await apiRequest(`reports?${query}`);
            tbody.replaceChildren();
            if (data.reports.length === 0) {
                const hasFilter = Object.keys(filters).length > 0;
                tbody.appendChild(messageRow(columns, hasFilter ? 'No reports match these filters.' : 'No reports yet.'));
                return;
            }
            data.reports.forEach((report) => tbody.appendChild(reportRow(report, isAdmin)));
            renderPagination(pager, data.pagination, (newPage) => { page = newPage; load(); });
        } catch (error) {
            tbody.replaceChildren();
            showAlert(alertBox, error.message);
        }
    }

    [statusFilter, zoneFilter, dateFrom, dateTo].filter(Boolean).forEach((control) => {
        control.addEventListener('change', () => { page = 1; load(); });
    });
    if (clearButton) {
        clearButton.addEventListener('click', () => {
            [statusFilter, zoneFilter, dateFrom, dateTo].filter(Boolean).forEach((control) => { control.value = ''; });
            page = 1;
            load();
        });
    }
    load();
}

function reportRow(report, isAdmin) {
    const viewLink = el('a', { class: 'btn btn-sm btn-outline-secondary', text: 'View', attrs: {
        href: `report.html?id=${report.report_id}`, 'aria-label': `View report ${report.report_id}`,
    } });

    const cells = [
        el('td', { class: 'text-nowrap', text: `#${report.report_id}` }),
        el('td', { class: 'text-nowrap', text: formatDateTime(report.date_reported) }),
    ];
    if (isAdmin) cells.push(el('td', { text: report.reporter_name }));
    cells.push(
        el('td', { text: ISSUE_LABELS[report.issue_type] || report.issue_type }),
        el('td', { text: report.zone_name }),
        el('td', {}, [statusBadge(report.status)]),
        el('td', { class: 'text-end' }, [viewLink]),
    );
    return el('tr', {}, cells);
}

/* report detail */

async function initReportDetail(user) {
    const isAdmin = user.role === 'admin';
    const root = document.getElementById('report-view');
    const params = new URLSearchParams(window.location.search);
    const id = params.get('id');
    const backHref = isAdmin ? 'admin-reports.html' : 'my-reports.html';

    document.getElementById('back-link').href = backHref;

    if (!/^\d+$/.test(id || '')) {
        showAlert(document.getElementById('detail-alert'), 'This report link is not valid.');
        return;
    }

    let notice = params.get('submitted') === '1' ? 'Report submitted. You can follow its status on this page.' : null;

    async function load() {
        try {
            const data = await apiRequest(`reports/${id}`);
            render(data.report);
        } catch (error) {
            root.replaceChildren();
            showAlert(document.getElementById('detail-alert'), error.message);
        }
    }

    function render(report) {
        const alertBox = document.getElementById('detail-alert');
        if (notice) {
            showAlert(alertBox, notice, 'success');
            notice = null;
        } else {
            alertBox.classList.add('d-none');
        }

        const parts = [detailCard(report, isAdmin), historyCard(report)];
        if (isAdmin) {
            parts.splice(1, 0, reporterCard(report.reporter));
            parts.push(statusFormCard(report));
        }
        root.replaceChildren(...parts);
    }

    // One labelled line in the details list.
    function row(label, valueNode) {
        return el('div', { class: 'row py-2 border-bottom' }, [
            el('dt', { class: 'col-sm-4 text-muted-soft fw-normal', text: label }),
            el('dd', { class: 'col-sm-8 mb-0' }, [valueNode]),
        ]);
    }

    function detailCard(report) {
        const waste = report.waste_types.length
            ? zoneBadges(report.waste_types.map((t) => ({ zone_name: t.type_name })))
            : el('span', { class: 'text-muted-soft', text: 'Not specified' });

        const list = el('dl', { class: 'mb-0' }, [
            row('Issue type', el('span', { text: ISSUE_LABELS[report.issue_type] || report.issue_type })),
            row('Zone', el('span', { text: `${report.zone.zone_name}, ${report.zone.barangay_name}` })),
            row('Waste types', waste),
            row('Reported on', el('span', { text: formatDateTime(report.date_reported) })),
            row('Description', el('div', { class: 'pre-wrap', text: report.issue_description })),
        ]);
        if (report.has_photo) {
            const img = el('img', { class: 'report-photo', attrs: {
                src: `${API_BASE}/reports/${report.report_id}/photo`, alt: `Photo attached to report ${report.report_id}`,
            } });
            const link = el('a', { attrs: { href: img.getAttribute('src'), target: '_blank', rel: 'noopener' } }, [img]);
            list.appendChild(row('Photo', link));
        }

        return el('section', { class: 'card mb-3' }, [el('div', { class: 'card-body' }, [
            el('div', { class: 'd-flex flex-wrap justify-content-between align-items-center gap-2 mb-3' }, [
                el('h2', { class: 'h5 mb-0', text: `Report #${report.report_id}` }),
                statusBadge(report.status),
            ]),
            list,
        ])]);
    }

    function reporterCard(reporter) {
        return el('section', { class: 'card mb-3' }, [el('div', { class: 'card-body' }, [
            el('h2', { class: 'h5 mb-3', text: 'Reported by' }),
            el('dl', { class: 'mb-0' }, [
                row('Name', el('span', { text: reporter.name })),
                row('Email', el('span', { text: reporter.email })),
                row('Phone', el('span', { text: reporter.phone_number || 'Not provided' })),
                row('Address', el('span', { text: reporter.address })),
            ]),
        ])]);
    }

    function historyCard(report) {
        const items = report.history.map((entry) => el('li', { class: 'list-group-item px-0' }, [
            el('div', { class: 'd-flex flex-wrap justify-content-between align-items-center gap-2' }, [
                statusBadge(entry.status),
                el('span', { class: 'text-muted-soft small', text: `${formatDateTime(entry.updated_at)} - ${entry.updated_by}` }),
            ]),
            entry.remarks ? el('p', { class: 'mb-0 mt-2 pre-wrap', text: entry.remarks }) : document.createTextNode(''),
        ]));

        return el('section', { class: 'card mb-3', attrs: { 'aria-labelledby': 'history-heading' } }, [el('div', { class: 'card-body' }, [
            el('h2', { class: 'h5 mb-2', text: 'Status history', attrs: { id: 'history-heading' } }),
            el('ol', { class: 'list-group list-group-flush list-unstyled mb-0' }, items),
        ])]);
    }

    function statusFormCard(report) {
        const select = el('select', { class: 'form-select', attrs: { id: 'new-status', name: 'status' } });
        Object.entries(STATUS_LABELS).forEach(([value, label]) => {
            const option = el('option', { text: label, attrs: { value } });
            if (value === report.status) option.selected = true;
            select.appendChild(option);
        });
        const remarks = el('textarea', { class: 'form-control', attrs: { id: 'remarks', name: 'remarks', rows: '3', maxlength: '500' } });
        const submit = el('button', { class: 'btn btn-brand', text: 'Update status', attrs: { type: 'submit' } });
        const formAlert = el('div', { class: 'alert d-none', attrs: { role: 'alert', 'aria-live': 'polite' } });

        const form = el('form', { attrs: { novalidate: '' } }, [
            formAlert,
            el('div', { class: 'mb-3' }, [
                el('label', { class: 'form-label', text: 'New status', attrs: { for: 'new-status' } }), select,
                el('div', { class: 'invalid-feedback', attrs: { 'data-error-for': 'status' } }),
            ]),
            el('div', { class: 'mb-3' }, [
                el('label', { class: 'form-label', text: 'Remarks (shown to the resident; required when rejecting)', attrs: { for: 'remarks' } }), remarks,
                el('div', { class: 'invalid-feedback', attrs: { 'data-error-for': 'remarks' } }),
            ]),
            submit,
        ]);

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            clearFormErrors(form, formAlert);
            setBusy(submit, true, 'Saving...');
            try {
                const data = await apiRequest(`reports/${report.report_id}/status`, {
                    method: 'PUT', body: { status: select.value, remarks: remarks.value },
                });
                notice = 'Status updated.';
                render(data.report);
            } catch (error) {
                showAlert(formAlert, error.message);
                showFieldErrors(form, error.errors);
                setBusy(submit, false);
            }
        });

        return el('section', { class: 'card mb-3' }, [el('div', { class: 'card-body' }, [
            el('h2', { class: 'h5 mb-3', text: 'Update status' }), form,
        ])]);
    }

    load();
}
