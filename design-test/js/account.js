(() => {
    const root = document.documentElement;
    const tabs = [...document.querySelectorAll('[data-account-tab]')];
    const panels = [...document.querySelectorAll('[data-account-panel]')];
    const toast = document.querySelector('#account-toast');
    let toastTimer;
    let passkeyPendingRemoval = null;

    const showToast = (message) => {
        window.clearTimeout(toastTimer);
        toast.textContent = message;
        toast.classList.add('toast--visible');
        toastTimer = window.setTimeout(() => toast.classList.remove('toast--visible'), 2800);
    };

    const openDialog = (dialog) => {
        if (dialog && typeof dialog.showModal === 'function') {
            dialog.showModal();
        }
    };

    const closeDialog = (dialog) => {
        if (dialog?.open) {
            dialog.close();
        }
    };

    const activateTab = (name, focusPanel = false) => {
        tabs.forEach((tab) => {
            const active = tab.dataset.accountTab === name;
            tab.classList.toggle('account-settings__tab--active', active);
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
        });

        panels.forEach((panel) => {
            panel.hidden = panel.dataset.accountPanel !== name;
        });

        if (focusPanel) {
            document.querySelector(`[data-account-panel="${name}"]`)?.focus();
        }

        history.replaceState(null, '', `#${name}`);
    };

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activateTab(tab.dataset.accountTab));
        tab.addEventListener('keydown', (event) => {
            let nextIndex = index;

            if (event.key === 'ArrowRight' || event.key === 'ArrowDown') nextIndex = (index + 1) % tabs.length;
            if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') nextIndex = (index - 1 + tabs.length) % tabs.length;
            if (event.key === 'Home') nextIndex = 0;
            if (event.key === 'End') nextIndex = tabs.length - 1;
            if (nextIndex === index && !['Home', 'End'].includes(event.key)) return;

            event.preventDefault();
            tabs[nextIndex].focus();
            activateTab(tabs[nextIndex].dataset.accountTab);
        });
    });

    const initialTab = window.location.hash.slice(1);
    if (tabs.some((tab) => tab.dataset.accountTab === initialTab)) {
        activateTab(initialTab);
    }

    const setFieldError = (field, message) => {
        const error = document.querySelector(`#${field.id}-error`);
        field.setAttribute('aria-invalid', message ? 'true' : 'false');
        if (error) error.textContent = message;
    };

    const profileForm = document.querySelector('#profile-form');
    const nameInput = document.querySelector('#account-name');
    const emailInput = document.querySelector('#account-email');

    profileForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const name = nameInput.value.trim();
        const email = emailInput.value.trim();
        const emailValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);

        setFieldError(nameInput, name.length >= 2 ? '' : 'Le nom doit contenir au moins 2 caractères.');
        setFieldError(emailInput, emailValid ? '' : 'Saisissez une adresse e-mail valide.');
        if (name.length < 2 || !emailValid) return;

        document.querySelectorAll('[data-account-full-name]').forEach((node) => {
            node.textContent = name;
        });
        document.querySelectorAll('[data-account-email]').forEach((node) => {
            node.textContent = email;
        });

        const words = name.split(/\s+/).filter(Boolean);
        const initials = `${words[0]?.[0] ?? ''}${words[1]?.[0] ?? ''}`.toUpperCase();
        document.querySelectorAll('[data-account-initials]').forEach((node) => {
            node.textContent = initials || '?';
        });
        document.querySelectorAll('[data-account-first-name]').forEach((node) => {
            node.textContent = words[0] || name;
        });

        document.querySelector('[data-profile-saved]').textContent = 'Modifications enregistrées.';
        showToast('Profil mis à jour.');
    });

    [nameInput, emailInput].forEach((field) => {
        field.addEventListener('input', () => setFieldError(field, ''));
    });

    if (new URLSearchParams(window.location.search).get('unverified') === '1') {
        document.querySelector('[data-email-verified]').hidden = true;
        document.querySelector('[data-email-verification]').hidden = false;
    }

    document.querySelector('[data-resend-verification]').addEventListener('click', () => {
        showToast('E-mail de vérification renvoyé.');
    });

    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const field = document.querySelector(`#${button.dataset.passwordToggle}`);
            const show = field.type === 'password';
            field.type = show ? 'text' : 'password';
            button.setAttribute('aria-label', `${show ? 'Masquer' : 'Afficher'} ${field.labels?.[0]?.textContent.toLowerCase() ?? 'le mot de passe'}`);
        });
    });

    const passwordForm = document.querySelector('#password-form');
    passwordForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const current = document.querySelector('#current-password');
        const password = document.querySelector('#new-password');
        const confirmation = document.querySelector('#password-confirmation');

        setFieldError(current, current.value ? '' : 'Saisissez votre mot de passe actuel.');
        setFieldError(password, password.value.length >= 8 ? '' : 'Utilisez au moins 8 caractères.');
        setFieldError(confirmation, confirmation.value === password.value && confirmation.value ? '' : 'Les mots de passe ne correspondent pas.');

        if (!current.value || password.value.length < 8 || confirmation.value !== password.value) return;

        passwordForm.reset();
        showToast('Mot de passe mis à jour.');
    });

    passwordForm.querySelectorAll('input').forEach((field) => {
        field.addEventListener('input', () => setFieldError(field, ''));
    });

    const twoFactorDialog = document.querySelector('#two-factor-dialog');
    const twoFactorEnable = document.querySelector('[data-two-factor-enable]');
    const twoFactorDisable = document.querySelector('[data-two-factor-disable]');
    const twoFactorStatus = document.querySelector('[data-two-factor-status]');
    const recoverySection = document.querySelector('[data-recovery-section]');
    const recoveryList = document.querySelector('[data-recovery-list]');

    const randomCode = () => {
        const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        const values = new Uint8Array(10);
        window.crypto?.getRandomValues(values);
        const raw = [...values].map((value) => alphabet[value % alphabet.length]).join('');
        return `${raw.slice(0, 5)}-${raw.slice(5)}`;
    };

    const fillRecoveryCodes = () => {
        recoveryList.replaceChildren(...Array.from({ length: 8 }, () => {
            const code = document.createElement('code');
            code.textContent = randomCode();
            return code;
        }));
    };

    twoFactorEnable.addEventListener('click', () => openDialog(twoFactorDialog));

    document.querySelector('[data-two-factor-form]').addEventListener('submit', (event) => {
        event.preventDefault();
        const code = document.querySelector('#two-factor-code');
        const valid = /^\d{6}$/.test(code.value.trim());
        setFieldError(code, valid ? '' : 'Saisissez les 6 chiffres affichés par votre application.');
        if (!valid) return;

        twoFactorStatus.textContent = 'Active';
        twoFactorStatus.classList.add('status-pill--success');
        twoFactorEnable.hidden = true;
        twoFactorDisable.hidden = false;
        recoverySection.hidden = false;
        fillRecoveryCodes();
        closeDialog(twoFactorDialog);
        showToast('Authentification à deux facteurs activée.');
    });

    twoFactorDisable.addEventListener('click', () => {
        twoFactorStatus.textContent = 'Inactive';
        twoFactorStatus.classList.remove('status-pill--success');
        twoFactorEnable.hidden = false;
        twoFactorDisable.hidden = true;
        recoverySection.hidden = true;
        recoveryList.hidden = true;
        showToast('Authentification à deux facteurs désactivée.');
    });

    document.querySelector('[data-recovery-toggle]').addEventListener('click', (event) => {
        recoveryList.hidden = !recoveryList.hidden;
        event.currentTarget.textContent = recoveryList.hidden ? 'Afficher les codes de récupération' : 'Masquer les codes de récupération';
    });

    document.querySelector('[data-recovery-regenerate]').addEventListener('click', () => {
        fillRecoveryCodes();
        recoveryList.hidden = false;
        showToast('Nouveaux codes de récupération générés.');
    });

    const passkeyForm = document.querySelector('[data-passkey-form]');
    const passkeyName = document.querySelector('#passkey-name');
    const passkeyList = document.querySelector('[data-passkey-list]');
    const passkeyRemoveDialog = document.querySelector('#passkey-remove-dialog');

    document.querySelector('[data-passkey-add]').addEventListener('click', () => {
        passkeyForm.hidden = false;
        passkeyName.focus();
    });

    document.querySelector('[data-passkey-cancel]').addEventListener('click', () => {
        passkeyForm.hidden = true;
        passkeyForm.reset();
    });

    passkeyForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const name = passkeyName.value.trim();
        if (!name) {
            passkeyName.focus();
            return;
        }

        const fragment = document.querySelector('#passkey-item-template').content.cloneNode(true);
        fragment.querySelector('.passkey-item__name').textContent = name;
        fragment.querySelector('[data-passkey-remove]').setAttribute('aria-label', `Supprimer la passkey ${name}`);
        passkeyList.append(fragment);
        passkeyForm.reset();
        passkeyForm.hidden = true;
        showToast('Passkey ajoutée.');
    });

    passkeyList.addEventListener('click', (event) => {
        const removeButton = event.target.closest('[data-passkey-remove]');
        if (!removeButton) return;
        passkeyPendingRemoval = removeButton.closest('[data-passkey-item]');
        openDialog(passkeyRemoveDialog);
    });

    document.querySelector('[data-passkey-confirm-remove]').addEventListener('click', () => {
        passkeyPendingRemoval?.remove();
        passkeyPendingRemoval = null;
        closeDialog(passkeyRemoveDialog);
        showToast('Passkey supprimée.');
    });

    document.querySelectorAll('[data-dialog-close]').forEach((button) => {
        button.addEventListener('click', () => closeDialog(document.querySelector(`#${button.dataset.dialogClose}`)));
    });

    document.querySelectorAll('.account-dialog').forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) closeDialog(dialog);
        });
    });

    const appearanceInputs = [...document.querySelectorAll('input[name="appearance"]')];
    const appearanceStatus = document.querySelector('[data-appearance-status]');
    const appearanceLabels = {
        light: 'Le thème clair est actif.',
        dark: 'Le thème sombre est actif.',
        system: 'Le thème système est actif.',
    };

    const applyAppearance = (value, announce = false) => {
        root.dataset.appearance = value;
        localStorage.setItem('tripleframes-appearance', value);
        const input = appearanceInputs.find((candidate) => candidate.value === value);
        if (input) input.checked = true;
        appearanceStatus.textContent = appearanceLabels[value];
        if (announce) showToast('Préférence d’apparence enregistrée.');
    };

    const storedAppearance = localStorage.getItem('tripleframes-appearance');
    applyAppearance(appearanceLabels[storedAppearance] ? storedAppearance : 'system');

    appearanceInputs.forEach((input) => {
        input.addEventListener('change', () => applyAppearance(input.value, true));
    });

    document.querySelector('[data-account-logout]').addEventListener('click', () => {
        showToast('Déconnexion prête à être reliée à Laravel.');
    });
})();
