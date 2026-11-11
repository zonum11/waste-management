/* reports.js - screens to create, list and view waste reports. */

const MAX_PHOTOS = 2;
const MAX_PHOTO_TOTAL_BYTES = 15 * 1024 * 1024;   // both photos together
const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

// 1536000 -> "1.5 MB"
function formatFileSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

// Large photo preview in a Bootstrap modal. Built once, then reused.
// It closes with the X button, a click outside the picture, or the Esc key.
function openPhotoViewer(src, alt, details) {
    let modal = document.getElementById('photo-modal');
    if (!modal) {
        modal = el('div', { class: 'modal fade photo-modal', attrs: { id: 'photo-modal', tabindex: '-1', 'aria-label': 'Photo preview', 'aria-hidden': 'true' } }, [
            el('div', { class: 'modal-dialog modal-dialog-centered' }, [
                el('div', { class: 'modal-content' }, [
                    el('button', { class: 'btn-close btn-close-white photo-modal-close', attrs: { type: 'button', 'data-bs-dismiss': 'modal', 'aria-label': 'Close' } }),
                    el('img', { class: 'photo-modal-img', attrs: { id: 'photo-modal-img', src: '', alt: '' } }),
                    el('p', { class: 'photo-modal-caption', attrs: { id: 'photo-modal-caption' } }),
                ]),
            ]),
        ]);
        document.body.appendChild(modal);
    }
    const image = document.getElementById('photo-modal-img');
    image.setAttribute('src', src);
    image.setAttribute('alt', alt);
    document.getElementById('photo-modal-caption').textContent = details.join(' • ');
    bootstrap.Modal.getOrCreateInstance(modal).show();
}

/* new report */

// onSubmitted() runs after a report is saved, so the dashboard can refresh its list.
// defaultZoneId pre-selects the resident's own zone. Returns setZone() so the page can follow zone changes.
async function initNewReport(onSubmitted, defaultZoneId) {
    const form = document.getElementById('report-form');
    const alertBox = document.getElementById('form-alert');
    const button = document.getElementById('submit-btn');
    const typeBox = document.getElementById('waste-type-checkboxes');
    let selectedZone = defaultZoneId;   // the resident's zone, kept up to date by setZone()

    // Load zones and waste types from the API.
    try {
        const [zones, wasteTypes] = await Promise.all([apiRequest('zones'), apiRequest('waste-types')]);
        zones.zones.forEach((zone) => {
            form.elements.zone_id.appendChild(el('option', { text: zone.address, attrs: { value: String(zone.zone_id) } }));
        });
        wasteTypes.waste_types.forEach((type) => {
            const id = `waste-type-${type.type_id}`;
            typeBox.appendChild(el('span', {}, [
                el('input', { class: 'pill-input', attrs: { type: 'checkbox', id, value: String(type.type_id) } }),
                el('label', { class: 'pill', text: type.type_name, attrs: { for: id } }),
            ]));
        });
        if (defaultZoneId) form.elements.zone_id.value = String(defaultZoneId);
    } catch (error) {
        showAlert(alertBox, error.message);
        return;
    }

    Object.entries(ISSUE_LABELS).forEach(([value, label]) => {
        form.elements.issue_type.appendChild(el('option', { text: label, attrs: { value } }));
    });

    // Live character counter under the description.
    const counter = document.getElementById('description-count');
    const description = form.elements.issue_description;
    const updateCounter = () => { if (counter) counter.textContent = `${description.value.length}/${description.maxLength}`; };
    description.addEventListener('input', updateCounter);

    // Photos: 1 is required and 2 are allowed. Each pick is added to this list, so a phone can
    // take one photo, then another. The file input only picks; the list is what gets sent.
    const uploadBox = document.getElementById('upload-box');
    const uploadTitle = document.getElementById('upload-title');
    const photoInput = form.elements.photos;
    const photoList = document.getElementById('photo-files');
    let chosen = [];

    function renderChosen() {
        photoList.replaceChildren(...chosen.map((file, index) => {
            const remove = el('button', { class: 'btn-close photo-file-remove', attrs: { type: 'button', 'aria-label': `Remove ${file.name}` } });
            remove.addEventListener('click', () => {
                chosen.splice(index, 1);
                renderChosen();
            });
            return el('li', { class: 'photo-file' }, [
                el('i', { class: 'bi bi-image', attrs: { 'aria-hidden': 'true' } }),
                el('span', { class: 'photo-file-name', text: file.name }),
                el('span', { class: 'photo-file-size', text: formatFileSize(file.size) }),
                remove,
            ]);
        }));
        const full = chosen.length >= MAX_PHOTOS;
        photoInput.disabled = full;
        uploadBox.classList.toggle('is-full', full);
        uploadTitle.textContent = full ? 'Maximum of 2 photos added' : (chosen.length ? 'Add another photo' : 'Upload image here');
    }

    function addPhotos(files) {
        form.querySelector('[data-error-for="photos"]').textContent = '';
        for (const file of files) {
            if (chosen.length >= MAX_PHOTOS) {
                showFieldErrors(form, { photos: `You can upload up to ${MAX_PHOTOS} photos.` });
                break;
            }
            chosen.push(file);
        }
        renderChosen();
    }

    photoInput.addEventListener('change', () => {
        addPhotos(Array.from(photoInput.files));
        photoInput.value = '';   // so the same file can be picked again after removing it
    });
    if (uploadBox) {
        ['dragenter', 'dragover'].forEach((name) => uploadBox.addEventListener(name, (event) => {
            event.preventDefault();
            uploadBox.classList.add('dragging');
        }));
        ['dragleave', 'drop'].forEach((name) => uploadBox.addEventListener(name, () => uploadBox.classList.remove('dragging')));
        uploadBox.addEventListener('drop', (event) => {
            event.preventDefault();
            if (event.dataTransfer && event.dataTransfer.files.length > 0) {
                addPhotos(Array.from(event.dataTransfer.files));
            }
        });
    }

    // The form sits in a pop-up: start each opening with no old messages.
    const modalEl = form.closest('.modal');
    if (modalEl) {
        modalEl.addEventListener('show.bs.modal', () => clearFormErrors(form, alertBox));
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFormErrors(form, alertBox);

        // Quick browser check; the server checks again because it cannot trust the browser.
        let photoError = '';
        if (chosen.length === 0) {
            photoError = 'Please add at least 1 photo.';
        } else if (chosen.some((file) => !PHOTO_TYPES.includes(file.type))) {
            photoError = 'Photos must be JPEG, PNG or WebP images.';
        } else if (chosen.reduce((sum, file) => sum + file.size, 0) > MAX_PHOTO_TOTAL_BYTES) {
            photoError = 'Your photos must be 15 MB or smaller in total.';
        }
        if (photoError) {
            showFieldErrors(form, { photos: photoError });
            return;
        }

        // FormData can carry the photo files.
        const body = new FormData();
        body.append('zone_id', form.elements.zone_id.value);
        body.append('issue_type', form.elements.issue_type.value);
        body.append('issue_description', form.elements.issue_description.value);
        typeBox.querySelectorAll('input:checked').forEach((box) => body.append('waste_type_ids[]', box.value));
        chosen.forEach((file) => body.append('photos[]', file));

        setBusy(button, true, 'Submitting...');
        try {
            const data = await apiRequest('reports', { method: 'POST', body });
            form.reset();
            if (selectedZone) form.elements.zone_id.value = String(selectedZone);   // reset() cleared it
            updateCounter();
            chosen = [];
            renderChosen();
            const thanks = `Report #${data.report.report_id} submitted. Thank you!`;
            if (modalEl) {
                // Close the pop-up and show the thank-you above the "My reports" list.
                bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                if (onSubmitted) onSubmitted();
                showAlert(document.getElementById('report-alert'), thanks, 'success');
            } else {
                showAlert(alertBox, thanks, 'success');
                if (onSubmitted) onSubmitted();
            }
        } catch (error) {
            showAlert(alertBox, error.message);
            showFieldErrors(form, error.errors);
        }
        setBusy(button, false);
    });

    return { setZone(zoneId) { selectedZone = zoneId; form.elements.zone_id.value = String(zoneId); } };
}

/* report list */

function initReportList(isAdmin) {
    const statusFilter = document.getElementById('status-filter');
    // These exist only on the admin page, so they are checked before use.
    const zoneFilter = document.getElementById('zone-filter');

    // Admins see a table; residents see report cards (the container is a <div> there).
    const tbody = document.getElementById('report-body');
    const alertBox = document.getElementById('report-alert');
    const pager = document.getElementById('pagination');
    const columns = isAdmin ? 7 : 6;
    const emptyMessage = (text) => (isAdmin ? messageRow(columns, text) : el('p', { class: 'report-empty', text }));
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
                zoneFilter.appendChild(el('option', { text: zone.address, attrs: { value: String(zone.zone_id) } }));
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
        };
        return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));
    }

    async function load() {
        tbody.replaceChildren(emptyMessage('Loading reports...'));
        await loadZoneNames();
        pager.replaceChildren();
        alertBox.classList.add('d-none');

        const filters = activeFilters();
        const query = new URLSearchParams({ page: String(page), ...filters });

        try {
            const data = await apiRequest(`reports?${query}`);
            tbody.replaceChildren();
            if (data.reports.length === 0) {
                const hasFilter = Object.keys(filters).length > 0;
                tbody.appendChild(emptyMessage(hasFilter ? 'No reports match these filters.' : 'No reports yet.'));
                return;
            }
            data.reports.forEach((report) => tbody.appendChild(isAdmin ? reportRow(report, true) : reportCard(report)));
            renderPagination(pager, data.pagination, (newPage) => { page = newPage; load(); });
        } catch (error) {
            tbody.replaceChildren();
            showAlert(alertBox, error.message);
        }
    }

    [statusFilter, zoneFilter].filter(Boolean).forEach((control) => {
        control.addEventListener('change', () => { page = 1; load(); });
    });
    load();

    // Lets the dashboard refresh the list, or show one status (used by the admin tiles).
    return {
        reload() { page = 1; load(); },
        showStatus(status) {
            if (zoneFilter) { zoneFilter.value = ''; }
            statusFilter.value = status;
            page = 1;
            load();
        },
    };
}

// "November 8, 2026" and "4:11 PM" from a stored date, without time-zone shifting.
function longDateParts(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(value || '');
    if (!match) return { date: value || '', time: '' };
    const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    return { date: `${months[Number(match[2]) - 1]} ${Number(match[3])}, ${match[1]}`, time: formatTime(`${match[4]}:${match[5]}`) };
}

// One report as a card on the resident dashboard.
function reportCard(report) {
    const when = longDateParts(report.date_reported);
    const streams = report.waste_types && report.waste_types.length ? report.waste_types.join(', ') : 'Not specified';
    return el('article', { class: 'report-item' }, [
        el('div', { class: 'report-top' }, [
            el('div', {}, [
                el('p', { class: 'report-status', text: STATUS_LABELS[report.status] || report.status }),
                el('h3', { class: 'report-title', text: ISSUE_LABELS[report.issue_type] || report.issue_type }),
                el('p', { class: 'report-zone', text: `${fullZoneName(report.zone_name)} \u2022 Report #${report.report_id}` }),
            ]),
            el('div', { class: 'report-when' }, [
                el('p', { class: 'report-date', text: when.date }),
                el('p', { class: 'report-time', text: when.time }),
            ]),
        ]),
        el('p', { class: 'report-streams' }, [document.createTextNode('Streams Reported: '), el('span', { text: streams })]),
        el('p', { class: 'report-quote', text: `\u201C${report.issue_description}\u201D` }),
        el('a', { class: 'report-more', text: 'View Full Report', attrs: {
            href: `report.html?id=${report.report_id}`, 'aria-label': `View full report ${report.report_id}`,
        } }),
    ]);
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
        el('td', { text: fullZoneName(report.zone_name) }),
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
    const backHref = isAdmin ? 'admin.html#reports' : 'resident.html#my-reports';

    document.getElementById('back-link').href = backHref;

    if (!/^\d+$/.test(id || '')) {
        showAlert(document.getElementById('detail-alert'), 'This report link is not valid.');
        return;
    }

    let notice = params.get('submitted') === '1' ? 'Report submitted. You can follow its status on this page.' : null;

    async function load() {
        try {
            await loadZoneNames();
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
            row('Zone', el('span', { text: fullZoneName(report.zone.zone_name) })),
            row('Waste types', waste),
            row('Reported on', el('span', { text: formatDateTime(report.date_reported) })),
            row('Description', el('div', { class: 'pre-wrap', text: report.issue_description })),
        ]);
        if (report.photos.length > 0) {
            const blocks = report.photos.map((photo, index) => photoBlock(report, photo, index + 1));
            list.appendChild(row(report.photos.length > 1 ? 'Photos' : 'Photo', el('div', { class: 'photo-list' }, blocks)));
        }

        return el('section', { class: 'card mb-3' }, [el('div', { class: 'card-body' }, [
            el('div', { class: 'd-flex flex-wrap justify-content-between align-items-center gap-2 mb-3' }, [
                el('h2', { class: 'h5 mb-0', text: `Report #${report.report_id}` }),
                statusBadge(report.status),
            ]),
            list,
        ])]);
    }

    // Small thumbnail with file details; clicking it opens the large preview.
    function photoBlock(report, photo, number) {
        const several = report.photos.length > 1;
        const title = several ? `Photo ${number} of ${report.photos.length}` : 'Attached photo';
        const src = `${API_BASE}/reports/${report.report_id}/photos/${photo.photo_id}`;
        const alt = `${title} for report ${report.report_id}`;
        const details = [photo.type, formatFileSize(photo.size)];

        const thumb = el('img', { class: 'photo-thumb', attrs: { src, alt } });
        const button = el('button', { class: 'photo-thumb-btn', attrs: { type: 'button', 'aria-label': 'View photo larger' } }, [
            thumb,
            el('span', { class: 'photo-zoom', attrs: { 'aria-hidden': 'true' } }, [el('i', { class: 'bi bi-arrows-fullscreen' })]),
        ]);
        const meta = el('div', { class: 'photo-meta' }, [
            el('div', { class: 'photo-meta-title', text: title }),
            el('div', { class: 'photo-meta-details text-muted-soft small', text: details.join(' • ') }),
        ]);

        // The image size in pixels is only known once the picture has loaded.
        thumb.addEventListener('load', () => {
            details.push(`${thumb.naturalWidth} × ${thumb.naturalHeight} px`);
            meta.querySelector('.photo-meta-details').textContent = details.join(' • ');
        });
        button.addEventListener('click', () => openPhotoViewer(src, alt, several ? [title, ...details] : details));

        return el('div', { class: 'photo-block' }, [button, meta]);
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

    // Every status change with its date, who made it, and the remarks (residents see the remarks too).
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
