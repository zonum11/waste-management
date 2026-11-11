/* dashboard.js - the admin dashboard's overview: report counts and totals. */

// Each tile shows the report list below filtered by that status ('' means all).
const SUMMARY_TILES = [
    { key: 'total', label: 'Total reports', status: '', icon: 'bi-inboxes' },
    { key: 'pending', label: 'Pending', status: 'pending', icon: 'bi-hourglass-split' },
    { key: 'in_progress', label: 'In progress', status: 'in_progress', icon: 'bi-arrow-repeat' },
    { key: 'resolved', label: 'Resolved', status: 'resolved', icon: 'bi-check-circle' },
    { key: 'rejected', label: 'Rejected', status: 'rejected', icon: 'bi-x-circle' },
];

// reportList (from initReportList) lets a tile filter the report list on this same page.
// Returns refresh(), which reloads the numbers after something changes.
function initAdminDashboard(reportList) {
    const tiles = document.getElementById('stat-tiles');
    if (!tiles) return () => {};   // this page has no overview section, so there is nothing to refresh
    const alertBox = document.getElementById('dashboard-alert');

    async function refresh() {
        try {
            const data = await apiRequest('dashboard');
            alertBox.classList.add('d-none');

            tiles.replaceChildren(...SUMMARY_TILES.map((tile) => {
                const link = el('a', { class: 'card stat-tile h-100 text-decoration-none', attrs: { href: '#reports' } }, [
                    el('div', { class: 'card-body' }, [
                        el('div', { class: 'd-flex justify-content-between align-items-start text-muted-soft' }, [
                            el('span', { text: tile.label }),
                            el('i', { class: `bi ${tile.icon}`, attrs: { 'aria-hidden': 'true' } }),
                        ]),
                        el('div', { class: 'stat-number', text: String(data.report_counts[tile.key]) }),
                    ]),
                ]);
                link.addEventListener('click', () => reportList.showStatus(tile.status));
                return el('div', { class: 'col-6 col-md-4 col-xl' }, [link]);
            }));

            document.getElementById('active-schedules').textContent = String(data.active_schedules);
            document.getElementById('resident-count').textContent = String(data.residents);
        } catch (error) {
            tiles.replaceChildren();
            showAlert(alertBox, error.message);
        }
    }

    refresh();
    return refresh;
}
