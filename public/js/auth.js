/* auth.js - login, registration, logout, and page protection. */

function dashboardFor(role) {
    return role === 'admin' ? 'admin.html' : 'resident.html';
}


function showAlert(box, message, type = 'danger') {
    box.className = `alert alert-${type}`;
    // textContent (not innerHTML) so user text cannot run as code (XSS).
    box.textContent = message;
    box.classList.remove('d-none');
}

function clearFormErrors(form, alertBox) {
    alertBox.classList.add('d-none');
    form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
    form.querySelectorAll('.invalid-feedback').forEach((el) => { el.textContent = ''; });
}

function showFieldErrors(form, errors) {
    for (const [field, message] of Object.entries(errors)) {
        const input = form.elements[field];
        const feedback = form.querySelector(`[data-error-for="${CSS.escape(field)}"]`);
        if (input && input.classList) input.classList.add('is-invalid');
        if (feedback) {
            feedback.textContent = message;
            feedback.classList.add('d-block');
        }
    }
}

function setBusy(button, busy, busyText) {
    if (busy) {
        button.dataset.label = button.textContent;
        button.textContent = busyText;
    } else {
        button.textContent = button.dataset.label;
    }
    button.disabled = busy;
}

/* Eye buttons: show or hide the passwords. data-password-toggle lists the boxes it controls
   (on the Register page one eye controls both password boxes and the two eyes stay in step). */
function initPasswordToggles() {
    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        const group = button.dataset.passwordToggle;
        const inputs = group.split(' ').map((id) => document.getElementById(id));
        const buttons = document.querySelectorAll(`[data-password-toggle="${group}"]`);

        button.addEventListener('click', () => {
            const show = inputs[0].type === 'password';
            inputs.forEach((input) => { input.type = show ? 'text' : 'password'; });
            buttons.forEach((eye) => {
                eye.setAttribute('aria-pressed', String(show));
                eye.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                eye.firstElementChild.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
            });
        });
    });
}

/* ---------- login page ---------- */

function initLoginPage() {
    const form = document.getElementById('login-form');
    const alertBox = document.getElementById('form-alert');
    const button = document.getElementById('submit-btn');
    initPasswordToggles();

    // Logged-in users skip the login page.
    apiRequest('auth/me').then((data) => {
        window.location.replace(dashboardFor(data.user.role));
    }).catch(() => { /* not logged in */ });

    const params = new URLSearchParams(window.location.search);
    if (params.get('registered') === '1') {
        showAlert(alertBox, 'Registration successful. Please log in.', 'success');
    } else if (params.get('reset') === '1') {
        showAlert(alertBox, 'Your password has been changed. Please log in.', 'success');
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFormErrors(form, alertBox);
        setBusy(button, true, 'Signing in...');

        try {
            const data = await apiRequest('auth/login', {
                method: 'POST',
                body: { email: form.elements.email.value, password: form.elements.password.value },
            });
            resetCsrfToken(data.csrf_token);
            window.location.replace(dashboardFor(data.user.role));
        } catch (error) {
            showAlert(alertBox, error.message);
            showFieldErrors(form, error.errors);
            setBusy(button, false);
        }
    });
}

/* ---------- registration page ---------- */

function initRegisterPage() {
    const form = document.getElementById('register-form');
    const alertBox = document.getElementById('form-alert');
    const button = document.getElementById('submit-btn');
    initPasswordToggles();

    // The address list comes from the zones (public, so it loads before anyone is logged in).
    apiRequest('zones').then((data) => {
        data.zones.forEach((zone) => {
            form.elements.zone_id.appendChild(el('option', { text: zone.address, attrs: { value: String(zone.zone_id) } }));
        });
    }).catch((error) => showAlert(alertBox, error.message));

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFormErrors(form, alertBox);

        // Quick browser check; the server checks again because it cannot trust the browser.
        if (form.elements.password.value !== form.elements.password_confirm.value) {
            showFieldErrors(form, { password_confirm: 'Passwords do not match.' });
            return;
        }

        setBusy(button, true, 'Creating account...');
        const body = {};
        ['fname', 'lname', 'email', 'phone_number', 'zone_id', 'password'].forEach((name) => {
            body[name] = form.elements[name].value;
        });

        try {
            await apiRequest('auth/register', { method: 'POST', body });
            window.location.replace('login.html?registered=1');
        } catch (error) {
            showAlert(alertBox, error.message);
            showFieldErrors(form, error.errors);
            setBusy(button, false);
        }
    });
}

/* ---------- forgot password page ---------- */

function initForgotPage() {
    const form = document.getElementById('forgot-form');
    const alertBox = document.getElementById('form-alert');
    const button = document.getElementById('submit-btn');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFormErrors(form, alertBox);
        setBusy(button, true, 'Sending...');

        try {
            const data = await apiRequest('auth/forgot-password', { method: 'POST', body: { email: form.elements.email.value } });
            // The same message is shown whether or not the email is registered.
            showAlert(alertBox, data.message || 'If that email is registered, a reset link has been sent.', 'success');
            form.reset();
        } catch (error) {
            showAlert(alertBox, error.message);
            showFieldErrors(form, error.errors);
        }
        setBusy(button, false);
    });
}

/* ---------- reset password page (opened from the emailed link) ---------- */

function initResetPage() {
    const form = document.getElementById('reset-form');
    const alertBox = document.getElementById('form-alert');
    const button = document.getElementById('submit-btn');
    const token = new URLSearchParams(window.location.search).get('token') || '';
    initPasswordToggles();

    if (!/^[a-f0-9]{64}$/.test(token)) {
        showAlert(alertBox, 'This reset link is not valid. Please request a new one.');
        button.disabled = true;
        return;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFormErrors(form, alertBox);

        // Quick browser check; the server checks again because it cannot trust the browser.
        if (form.elements.password.value !== form.elements.password_confirm.value) {
            showFieldErrors(form, { password_confirm: 'Passwords do not match.' });
            return;
        }

        setBusy(button, true, 'Saving...');
        try {
            await apiRequest('auth/reset-password', { method: 'POST', body: { token, password: form.elements.password.value } });
            window.location.replace('login.html?reset=1');
        } catch (error) {
            showAlert(alertBox, error.message);
            showFieldErrors(form, error.errors);
            setBusy(button, false);
        }
    });
}

/* ---------- dashboard pages ---------- */

/* Redirects wrong visitors; the real protection is the API checking every request. */
async function protectPage(allowedRoles, activeKey) {
    const roles = Array.isArray(allowedRoles) ? allowedRoles : [allowedRoles];

    let user;
    try {
        user = (await apiRequest('auth/me')).user;
    } catch (error) {
        window.location.replace('login.html');
        return null;
    }

    if (!roles.includes(user.role)) {
        window.location.replace(dashboardFor(user.role));
        return null;
    }

    renderNavbar(user, activeKey);
    document.getElementById('page-content').classList.remove('d-none');
    return user;
}

async function logout() {
    try {
        await apiRequest('auth/logout', { method: 'POST' });
    } catch (error) {
        /* ignore the error and leave the page anyway */
    }
    resetCsrfToken();
    window.location.replace('login.html');
}
