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

// Full address text of each zone ("Zone 1" becomes "Barangay Pogi Zone 1, Sitio Merete"), loaded once from the public zone list.
let zoneAddresses = null;
async function loadZoneNames() {
    if (zoneAddresses) return;
    zoneAddresses = {};
    try {
        const data = await apiRequest('zones');
        data.zones.forEach((zone) => { zoneAddresses[zone.zone_name] = zone.address; });
    } catch (error) { /* keep the short zone names */ }
}
function fullZoneName(name) {
    return (zoneAddresses && zoneAddresses[name]) || name;
}

// Small labels for each zone.
function zoneBadges(zones) {
    const wrap = el('div', { class: 'd-flex flex-wrap gap-1' });
    zones.forEach((zone) => {
        wrap.appendChild(el('span', { class: 'badge text-bg-light border fw-normal', text: fullZoneName(zone.zone_name) }));
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

// "Zone 2 • Sitio Munoz" from the saved address ("Barangay Pogi Zone 2, Sitio Munoz"); falls back to the zone name.
function zoneLabel(user) {
    const match = /(Zone\s*\d+),\s*(.+)$/.exec(user.address || '');
    if (match) return `${match[1]} \u2022 ${match[2]}`;
    return user.zone ? user.zone.zone_name : '';
}

// "Barangay Pogi" from the saved address, or null when the account has none (admins).
function barangayOf(user) {
    const match = /^(.*?)\s+Zone\s*\d+/.exec(user.address || '');
    return match ? match[1] : null;
}

/* Dark mode: the choice is remembered in this browser only. Light is the default. */
function savedTheme() {
    try { return localStorage.getItem('theme') === 'dark' ? 'dark' : 'light'; } catch (error) { return 'light'; }
}
function currentTheme() { return document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light'; }
function applyTheme(theme) {
    document.documentElement.setAttribute('data-bs-theme', theme);
    try { localStorage.setItem('theme', theme); } catch (error) { /* private mode: it just will not be remembered */ }
}
document.documentElement.setAttribute('data-bs-theme', savedTheme());

// Builds the top bar: logo and barangay name on the left, the user's menu (Profile, Log out) on the right.
function renderNavbar(user) {
    const initial = (user.fname || '?').charAt(0).toUpperCase();
    const barangay = barangayOf(user);

    const brandName = el('span', { class: 'app-brand-name', text: barangay || 'Barangay', attrs: { id: 'brand-name' } });
    const brand = el('a', { class: 'app-brand', attrs: { href: dashboardFor(user.role), 'aria-label': 'Home' } }, [
        el('i', { class: 'bi bi-recycle app-brand-mark', attrs: { 'aria-hidden': 'true' } }),
        el('span', { class: 'app-brand-text' }, [
            brandName,
            el('span', { class: 'app-brand-sub', text: 'WASTE MANAGEMENT SYSTEM' }),
        ]),
    ]);
    // Admins have no address, so the barangay name comes from the public zone list.
    if (!barangay) {
        apiRequest('zones').then((data) => {
            if (data.zones.length > 0) brandName.textContent = data.zones[0].barangay_name;
        }).catch(() => { /* keep the default name */ });
    }

    const toggle = el('button', { class: 'user-toggle', attrs: {
        type: 'button', 'data-bs-toggle': 'dropdown', 'aria-expanded': 'false', 'aria-label': 'Account menu', id: 'user-menu-btn',
    } }, [
        el('span', { class: 'user-avatar', text: initial, attrs: { id: 'user-avatar', 'aria-hidden': 'true' } }),
        el('span', { class: 'user-meta' }, [
            el('strong', { text: `${user.fname} ${user.lname}`, attrs: { id: 'user-name' } }),
            el('small', { text: user.role === 'admin' ? 'Administrator' : zoneLabel(user), attrs: { id: 'user-zone' } }),
        ]),
    ]);

    // "Profile" opens the pop-up when this page has one; elsewhere it is a normal link to the dashboard.
    const items = [];
    if (user.role === 'resident') {
        const profileLink = el('a', { class: 'dropdown-item', attrs: { href: 'resident.html#profile' } }, [
            el('i', { class: 'bi bi-person-circle me-2', attrs: { 'aria-hidden': 'true' } }),
            document.createTextNode('Profile'),
        ]);
        if (document.getElementById('profile-modal')) {
            profileLink.setAttribute('data-bs-toggle', 'modal');
            profileLink.setAttribute('data-bs-target', '#profile-modal');
        }
        items.push(el('li', {}, [profileLink]));
    }
    const themeIcon = el('i', { class: 'bi me-2', attrs: { 'aria-hidden': 'true' } });
    const themeLabel = document.createTextNode('');
    const themeButton = el('button', { class: 'dropdown-item', attrs: { type: 'button', id: 'theme-btn' } }, [themeIcon, themeLabel]);
    function showThemeButton() {
        const dark = currentTheme() === 'dark';
        themeIcon.className = `bi me-2 ${dark ? 'bi-sun' : 'bi-moon'}`;
        themeLabel.textContent = dark ? 'Light mode' : 'Dark mode';
    }
    themeButton.addEventListener('click', () => {
        applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
        showThemeButton();
    });
    showThemeButton();
    items.push(el('li', {}, [themeButton]));

    const logoutButton = el('button', { class: 'dropdown-item', attrs: { type: 'button', id: 'logout-btn' } }, [
        el('i', { class: 'bi bi-box-arrow-right me-2', attrs: { 'aria-hidden': 'true' } }),
        document.createTextNode('Log out'),
    ]);
    logoutButton.addEventListener('click', logout);
    items.push(el('li', {}, [logoutButton]));

    const menu = el('div', { class: 'dropdown user-menu' }, [
        toggle,
        el('ul', { class: 'dropdown-menu dropdown-menu-md-end' }, items),
    ]);

    const header = el('nav', { class: 'app-header', attrs: { 'aria-label': 'Main' } }, [
        el('div', { class: 'container app-header-inner' }, [
            brand,
            el('i', { class: 'bi bi-recycle app-mobile-mark', attrs: { 'aria-hidden': 'true' } }),
            menu,
        ]),
    ]);

    document.getElementById('app-nav').replaceChildren(header);
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
