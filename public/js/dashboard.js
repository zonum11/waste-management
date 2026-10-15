/* dashboard.js - the admin dashboard: report counts, latest reports, totals. */

// Each tile links to the report list filtered by that status.
const SUMMARY_TILES = [
    { key: 'total', label: 'Total reports', href: 'admin-reports.html', icon: 'bi-inboxes' },
    { key: 'pending', label: 'Pending', href: 'admin-reports.html?status=pending', icon: 'bi-hourglass-split' },
    { key: 'in_progress', label: 'In progress', href: 'admin-reports.html?status=in_progress', icon: 'bi-arrow-repeat' },
    { key: 'resolved', label: 'Resolved', href: 'admin-reports.html?status=resolved', icon: 'bi-check-circle' },
    { key: 'rejected', label: 'Rejected', href: 'admin-reports.html?status=rejected', icon: 'bi-x-circle' },
];

async function initAdminDashboard() {
    const tiles = document.getElementById('stat-tiles');
    const recentBody = document.getElementById('recent-body');
    const alertBox = document.getElementById('dashboard-alert');

    try {
        const data = await apiRequest('dashboard');

        tiles.replaceChildren(...SUMMARY_TILES.map((tile) => el('div', { class: 'col-6 col-md-4 col-xl' }, [
            el('a', { class: 'card stat-tile h-100 text-decoration-none', attrs: { href: tile.href } }, [
                el('div', { class: 'card-body' }, [
                    el('div', { class: 'd-flex justify-content-between align-items-start text-muted-soft' }, [
                        el('span', { text: tile.label }),
                        el('i', { class: `bi ${tile.icon}`, attrs: { 'aria-hidden': 'true' } }),
                    ]),
                    el('div', { class: 'stat-number', text: String(data.report_counts[tile.key]) }),
                ]),
            ]),
        ])));

        recentBody.replaceChildren();
        if (data.recent_reports.length === 0) {
            recentBody.appendChild(messageRow(7, 'No reports yet.'));
        } else {
            data.recent_reports.forEach((report) => recentBody.appendChild(reportRow(report, true)));
        }

        document.getElementById('active-schedules').textContent = String(data.active_schedules);
        document.getElementById('resident-count').textContent = String(data.residents);
    } catch (error) {
        tiles.replaceChildren();
        recentBody.replaceChildren();
        showAlert(alertBox, error.message);
    }
}
