/* ui.js - small helpers for building page content safely. */

// Uses textContent so database text is shown as plain text, never run (XSS).
function el(tag, options = {}, children = []) {
    const node = document.createElement(tag);
    if (options.class) node.className = options.class;
    if (options.text !== undefined) node.textContent = options.text;
    if (options.attrs) {
        for (const [name, value] of Object.entries(options.attrs)) node.setAttribute(name, value);
    }
    children.forEach((child) => node.appendChild(child));
    return node;
}

// Turns "07:30" into "7:30 AM".
function formatTime(hhmm) {
    const [hourText, minute] = hhmm.split(':');
    const hour = parseInt(hourText, 10);
    const suffix = hour >= 12 ? 'PM' : 'AM';
    return `${hour % 12 === 0 ? 12 : hour % 12}:${minute} ${suffix}`;
}

// Small labels for each zone.
function zoneBadges(zones) {
    const wrap = el('div', { class: 'd-flex flex-wrap gap-1' });
    zones.forEach((zone) => {
        wrap.appendChild(el('span', { class: 'badge text-bg-light border fw-normal', text: zone.zone_name }));
    });
    return wrap;
}

// A full-width table row for loading, empty and error messages.
function messageRow(columns, message) {
    const cell = el('td', { class: 'text-center text-muted-soft py-4', text: message, attrs: { colspan: String(columns) } });
    return el('tr', {}, [cell]);
}

const ISSUE_LABELS = {
    missed_collection: 'Missed collection',
    improper_disposal: 'Improper disposal',
    overflowing_garbage: 'Overflowing garbage',
    other: 'Other',
};

const STATUS_LABELS = {
    pending: 'Pending',
    in_progress: 'In progress',
    resolved: 'Resolved',
    rejected: 'Rejected',
};

const STATUS_STYLES = {
    pending: 'text-bg-warning',
    in_progress: 'text-bg-info',
    resolved: 'text-bg-success',
    rejected: 'text-bg-secondary',
};

// A coloured label; the text also carries the meaning, not just the colour.
function statusBadge(status) {
    return el('span', { class: `badge ${STATUS_STYLES[status] || 'text-bg-light'}`, text: STATUS_LABELS[status] || status });
}

// Formats a stored date as "Oct 13, 2026, 7:23 AM" without time-zone shifting.
function formatDateTime(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(value || '');
    if (!match) return value || '';
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return `${months[Number(match[2]) - 1]} ${Number(match[3])}, ${match[1]}, ${formatTime(`${match[4]}:${match[5]}`)}`;
}

const NAV_LINKS = {
    resident: [
        { key: 'schedule', href: 'resident.html', icon: 'bi-calendar-week', label: 'Schedule' },
        { key: 'new-report', href: 'report-new.html', icon: 'bi-plus-circle', label: 'New report' },
        { key: 'my-reports', href: 'my-reports.html', icon: 'bi-card-list', label: 'My reports' },
    ],
    admin: [
        { key: 'dashboard', href: 'admin.html', icon: 'bi-speedometer2', label: 'Dashboard' },
        { key: 'schedules', href: 'admin-schedules.html', icon: 'bi-calendar-week', label: 'Schedules' },
        { key: 'reports', href: 'admin-reports.html', icon: 'bi-card-list', label: 'Reports' },
    ],
};

// Builds the top bar; activeKey marks the current page's link.
function renderNavbar(user, activeKey) {
    const brand = el('span', { class: 'navbar-brand d-flex align-items-center gap-2 mb-0' }, [
        el('span', { class: 'brand-mark', attrs: { 'aria-hidden': 'true' } }, [el('i', { class: 'bi bi-recycle' })]),
        el('span', { text: 'Waste Management' }),
    ]);
    if (user.role === 'admin') {
        brand.appendChild(el('span', { class: 'badge text-bg-secondary ms-1', text: 'Admin' }));
    }

    const userName = el('span', { class: 'text-muted-soft d-none d-sm-inline' }, [
        el('i', { class: 'bi bi-person-circle', attrs: { 'aria-hidden': 'true' } }),
        document.createTextNode(' '),
        el('span', { text: `${user.fname} ${user.lname}`, attrs: { id: 'user-name' } }),
    ]);
    const logoutButton = el('button', { class: 'btn btn-outline-secondary btn-sm', attrs: { type: 'button', id: 'logout-btn' } }, [
        el('i', { class: 'bi bi-box-arrow-right', attrs: { 'aria-hidden': 'true' } }),
        document.createTextNode(' Log out'),
    ]);
    logoutButton.addEventListener('click', logout);

    const links = el('ul', { class: 'nav nav-underline flex-nowrap overflow-auto' });
    NAV_LINKS[user.role].forEach((link) => {
        const anchor = el('a', { class: `nav-link text-nowrap${link.key === activeKey ? ' active' : ''}`, attrs: { href: link.href } }, [
            el('i', { class: `bi ${link.icon} me-1`, attrs: { 'aria-hidden': 'true' } }),
            document.createTextNode(link.label),
        ]);
        if (link.key === activeKey) anchor.setAttribute('aria-current', 'page');
        links.appendChild(el('li', { class: 'nav-item' }, [anchor]));
    });

    const nav = el('nav', { class: 'app-navbar', attrs: { 'aria-label': 'Main' } }, [
        el('div', { class: 'container d-flex justify-content-between align-items-center py-2' }, [
            brand,
            el('div', { class: 'd-flex align-items-center gap-3' }, [userName, logoutButton]),
        ]),
        el('div', { class: 'container' }, [links]),
    ]);
    document.getElementById('app-nav').replaceChildren(nav);
}

// Previous / Next buttons; onPage(newPage) runs when one is clicked.
function renderPagination(container, pagination, onPage) {
    container.replaceChildren();
    if (pagination.total_pages <= 1) return;

    const prev = el('button', { class: 'btn btn-sm btn-outline-secondary', text: 'Previous', attrs: { type: 'button' } });
    const next = el('button', { class: 'btn btn-sm btn-outline-secondary', text: 'Next', attrs: { type: 'button' } });
    prev.disabled = pagination.page <= 1;
    next.disabled = pagination.page >= pagination.total_pages;
    prev.addEventListener('click', () => onPage(pagination.page - 1));
    next.addEventListener('click', () => onPage(pagination.page + 1));

    container.appendChild(el('div', { class: 'd-flex justify-content-between align-items-center mt-3' }, [
        prev,
        el('span', { class: 'text-muted-soft small', text: `Page ${pagination.page} of ${pagination.total_pages} (${pagination.total} reports)` }),
        next,
    ]));
}
