/* pages.js - runs the right starter for each page, chosen by <body data-page>. */

// No inline <script> in the HTML: the security policy only allows our own files.

const PAGE_STARTERS = {
    'login': () => initLoginPage(),
    'register': () => initRegisterPage(),

    // Resident pages
    'resident-schedule': () => protectPage('resident', 'schedule').then((user) => { if (user) initResidentSchedules(); }),
    'resident-report-new': () => protectPage('resident', 'new-report').then((user) => { if (user) initNewReport(); }),
    'resident-reports': () => protectPage('resident', 'my-reports').then((user) => { if (user) initReportList(false); }),

    // Admin pages
    'admin-dashboard': () => protectPage('admin', 'dashboard').then((user) => { if (user) initAdminDashboard(); }),
    'admin-schedules': () => protectPage('admin', 'schedules').then((user) => { if (user) initAdminSchedules(); }),
    'admin-reports': () => protectPage('admin', 'reports').then((user) => { if (user) initReportList(true); }),

    // Shared page: the API decides what each user may see.
    'report-detail': () => protectPage(['resident', 'admin'], null).then((user) => {
        if (!user) return;
        // Rebuild the menu so the right link is highlighted for this role.
        renderNavbar(user, user.role === 'admin' ? 'reports' : 'my-reports');
        initReportDetail(user);
    }),
};

const startPage = PAGE_STARTERS[document.body.dataset.page];
if (startPage) {
    startPage();
}
