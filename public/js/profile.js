/* profile.js - the resident's Profile pop-up (name, email, phone) and the Change Password pop-up. */

function initProfile(user) {
    const modalEl = document.getElementById('profile-modal');
    const form = document.getElementById('profile-form');
    const alertBox = document.getElementById('profile-alert');
    const button = document.getElementById('profile-save-btn');
    const passwordRow = document.getElementById('password-row');
    let saved = user;   // the last version stored on the server

    // Fills the form with the saved details and clears any password boxes.
    function fillForm() {
        form.elements.fname.value = saved.fname;
        form.elements.lname.value = saved.lname;
        form.elements.email.value = saved.email;
        form.elements.phone_number.value = saved.phone_number || '';
        form.elements.current_password.value = '';
        passwordRow.classList.add('d-none');
    }

    // The current password is only asked for when the email is being changed.
    function updatePasswordRow() {
        const emailChanged = form.elements.email.value.trim().toLowerCase() !== saved.email.toLowerCase();
        passwordRow.classList.toggle('d-none', !emailChanged);
        if (!emailChanged) form.elements.current_password.value = '';
    }
    form.elements.email.addEventListener('input', updatePasswordRow);

    // Each time the pop-up opens, show the saved details again (edits that were not saved are dropped).
    modalEl.addEventListener('show.bs.modal', () => {
        clearFormErrors(form, alertBox);
        fillForm();
    });
    fillForm();

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFormErrors(form, alertBox);

        const body = {
            fname: form.elements.fname.value.trim(),
            lname: form.elements.lname.value.trim(),
            email: form.elements.email.value.trim(),
            phone_number: form.elements.phone_number.value.trim(),
            current_password: form.elements.current_password.value,
        };

        setBusy(button, true, 'Saving...');
        try {
            const data = await apiRequest('auth/profile', { method: 'PUT', body });
            saved = data.user;
            fillForm();
            // The name in the top bar follows the change.
            const nameInBar = document.getElementById('user-name');
            if (nameInBar) nameInBar.textContent = `${saved.fname} ${saved.lname}`;
            const welcome = document.getElementById('welcome-name');
            if (welcome) welcome.textContent = `${saved.fname} ${saved.lname}`;
            const avatar = document.getElementById('user-avatar');
            if (avatar) avatar.textContent = saved.fname.charAt(0).toUpperCase();
            showAlert(alertBox, 'Profile saved.', 'success');
        } catch (error) {
            showAlert(alertBox, error.message);
            showFieldErrors(form, error.errors || {});
        }
        setBusy(button, false);
    });

    initChangePassword(modalEl);
}

/* ---------- Change Password pop-up ---------- */

// Same rules as the Register page (the server checks them again).
function passwordProblem(value) {
    if (value.length < 8) return 'Password must be at least 8 characters.';
    if (value.length > 72) return 'Password must be 72 characters or fewer.';
    if (!/[A-Za-z]/.test(value) || !/[0-9]/.test(value)) return 'Password must contain at least one letter and one number.';
    return '';
}

function initChangePassword(profileModalEl) {
    const modalEl = document.getElementById('password-modal');
    const form = document.getElementById('password-form');
    const alertBox = document.getElementById('password-alert');
    const button = document.getElementById('password-save-btn');
    initPasswordToggles();

    // The button in the Profile pop-up closes it, then opens this one.
    document.getElementById('change-password-btn').addEventListener('click', () => {
        profileModalEl.addEventListener('hidden.bs.modal', () => {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }, { once: true });
        bootstrap.Modal.getOrCreateInstance(profileModalEl).hide();
    });

    // Always start with empty boxes and no old messages.
    modalEl.addEventListener('show.bs.modal', () => {
        clearFormErrors(form, alertBox);
        form.reset();
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFormErrors(form, alertBox);

        const newPassword = form.elements.new_password.value;
        const confirmPassword = form.elements.confirm_password.value;
        const errors = {};
        if (newPassword === '') errors.new_password = 'Enter your new password.';
        else if (passwordProblem(newPassword)) errors.new_password = passwordProblem(newPassword);
        if (confirmPassword === '') errors.confirm_password = 'Confirm your new password.';
        else if (!errors.new_password && newPassword !== confirmPassword) errors.confirm_password = 'The two passwords do not match.';
        if (Object.keys(errors).length > 0) {
            showFieldErrors(form, errors);
            return;
        }

        setBusy(button, true, 'Saving...');
        try {
            await apiRequest('auth/password', { method: 'PUT', body: { new_password: newPassword, confirm_password: confirmPassword } });
            form.reset();
            showAlert(alertBox, 'Your password was changed.', 'success');
        } catch (error) {
            showAlert(alertBox, error.message);
            showFieldErrors(form, error.errors || {});
        }
        setBusy(button, false);
    });
}
