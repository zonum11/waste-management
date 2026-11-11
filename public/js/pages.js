/* pages.js - runs the right starter for each page, chosen by <body data-page>. */

// No inline <script> in the HTML: the security policy only allows our own files.

const PAGE_STARTERS = {
    'login': () => initLoginPage(),
    'register': () => initRegisterPage(),
    'forgot-password': () => initForgotPage(),
    'reset-password': () => initResetPage(),

    // Resident dashboard: next pickup banner and my reports; the report form and profile are pop-ups.
    'resident-dashboard': () => protectPage('resident', null).then((user) => {
        if (!user) return;
        initProfile(user);
        const reportList = initReportList(false);
        initNewReport(() => reportList.reload(), user.zone ? user.zone.zone_id : null);
        initResidentSchedules(user);
        document.getElementById('welcome-name').textContent = user.fname;
        document.getElementById('today-date').textContent = new Date().toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
        // The menu's Profile link on other pages leads here as resident.html#profile.
        if (window.location.hash === '#profile') {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('profile-modal')).show();
        }
    }),

    // Admin dashboard: overview, schedules and reports on one page.
    'admin-dashboard': () => protectPage('admin', null).then((user) => {
        if (!user) return;
        const reportList = initReportList(true);
        const refreshOverview = initAdminDashboard(reportList);
        initAdminSchedules(refreshOverview);
    }),

    // Shared page: the API decides what each user may see.
    'report-detail': () => protectPage(['resident', 'admin'], null).then((user) => {
        if (!user) return;
        // Rebuild the menu for this role; its links lead back to the dashboard sections.
        renderNavbar(user, null);
        initReportDetail(user);
    }),
};

const startPage = PAGE_STARTERS[document.body.dataset.page];
if (startPage) {
    startPage();
}
