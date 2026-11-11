/* profile.js - the resident's Profile pop-up: name, email, phone and password. */

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
        form.elements.new_password.value = '';
        form.elements.confirm_password.value = '';
        form.elements.current_password.value = '';
        passwordRow.classList.add('d-none');
    }

    // The current password is only asked for when the email or the password is being changed.
    function updatePasswordRow() {
        const emailChanged = form.elements.email.value.trim().toLowerCase() !== saved.email.toLowerCase();
        const passwordChanged = form.elements.new_password.value !== '';
        const needed = emailChanged || passwordChanged;
        passwordRow.classList.toggle('d-none', !needed);
        if (!needed) form.elements.current_password.value = '';
    }
    form.elements.email.addEventListener('input', updatePasswordRow);
    form.elements.new_password.addEventListener('input', updatePasswordRow);

    // Each time the pop-up opens, show the saved details again (edits that were not saved are dropped).
    modalEl.addEventListener('show.bs.modal', () => {
        clearFormErrors(form, alertBox);
        fillForm();
    });
    fillForm();

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFormErrors(form, alertBox);

        if (form.elements.new_password.value !== form.elements.confirm_password.value) {
            showFieldErrors(form, { confirm_password: 'The two passwords do not match.' });
            return;
        }

        const body = {
            fname: form.elements.fname.value.trim(),
            lname: form.elements.lname.value.trim(),
            email: form.elements.email.value.trim(),
            phone_number: form.elements.phone_number.value.trim(),
            current_password: form.elements.current_password.value,
            new_password: form.elements.new_password.value,
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
            if (welcome) welcome.textContent = saved.fname;
            const avatar = document.getElementById('user-avatar');
            if (avatar) avatar.textContent = saved.fname.charAt(0).toUpperCase();
            showAlert(alertBox, 'Profile saved.', 'success');
        } catch (error) {
            showAlert(alertBox, error.message);
            showFieldErrors(form, error.errors || {});
        }
        setBusy(button, false);
    });
}
