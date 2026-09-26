const authForm = document.querySelector('.auth-form');
const authToast = document.querySelector('#auth-toast');
let authToastTimer;

function showAuthToast(message) {
    authToast.textContent = message;
    authToast.classList.add('toast--visible');
    window.clearTimeout(authToastTimer);
    authToastTimer = window.setTimeout(() => {
        authToast.classList.remove('toast--visible');
    }, 3600);
}

function setAuthError(input, errorId, message = '') {
    const errorNode = document.querySelector(`#${errorId}`);
    input.setAttribute('aria-invalid', message ? 'true' : 'false');
    if (errorNode) errorNode.textContent = message;
}

function isEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
    toggle.addEventListener('click', () => {
        const input = document.querySelector(`#${toggle.dataset.passwordToggle}`);
        if (!input) return;

        const isVisible = input.type === 'text';
        input.type = isVisible ? 'password' : 'text';
        toggle.textContent = isVisible ? 'Afficher' : 'Masquer';
        toggle.setAttribute(
            'aria-label',
            isVisible ? 'Afficher le mot de passe' : 'Masquer le mot de passe',
        );
    });
});

document.querySelectorAll('.auth-field__input').forEach((input) => {
    input.addEventListener('input', () => {
        const errorId = input.getAttribute('aria-describedby')
            ?.split(' ')
            .find((id) => id.endsWith('-error'));
        if (errorId) setAuthError(input, errorId);
    });
});

const passwordInput = document.querySelector('#register-form #password');
const passwordMeter = document.querySelector('.password-meter');

passwordInput?.addEventListener('input', () => {
    const value = passwordInput.value;
    let strength = 0;
    if (value.length >= 8) strength += 1;
    if (/[a-z]/i.test(value) && /\d/.test(value)) strength += 1;
    if (/[A-Z]/.test(value) && /[a-z]/.test(value)) strength += 1;
    if (/[^A-Za-z0-9]/.test(value) && value.length >= 12) strength += 1;
    passwordMeter.dataset.strength = String(strength);
});

document.querySelector('#terms')?.addEventListener('change', (event) => {
    setAuthError(event.currentTarget, 'terms-error');
});

authForm?.addEventListener('submit', (event) => {
    event.preventDefault();
    const emailInput = authForm.elements.email;
    const password = authForm.elements.password;
    let firstInvalid = null;

    if (authForm.id === 'register-form') {
        const name = authForm.elements.name;

        if (name.value.trim().length < 3) {
            setAuthError(name, 'name-error', 'Choisis un pseudo d’au moins 3 caractères.');
            firstInvalid ??= name;
        }
    }

    if (!isEmail(emailInput.value.trim())) {
        setAuthError(emailInput, 'email-error', 'Entre une adresse e-mail valide.');
        firstInvalid ??= emailInput;
    }

    const passwordIsInvalid = authForm.id === 'register-form'
        ? password.value.length < 8
        : password.value.length === 0;

    if (passwordIsInvalid) {
        setAuthError(
            password,
            'password-error',
            authForm.id === 'register-form'
                ? 'Utilise au moins 8 caractères.'
                : 'Entre ton mot de passe.',
        );
        firstInvalid ??= password;
    }

    if (authForm.id === 'register-form') {
        const confirmation = authForm.elements.password_confirmation;
        const terms = authForm.elements.terms;

        if (confirmation.value !== password.value || !confirmation.value) {
            setAuthError(
                confirmation,
                'password-confirmation-error',
                'Les deux mots de passe doivent être identiques.',
            );
            firstInvalid ??= confirmation;
        }

        if (!terms.checked) {
            setAuthError(
                terms,
                'terms-error',
                'Accepte les conditions pour créer ton compte.',
            );
            firstInvalid ??= terms;
        }
    }

    if (firstInvalid) {
        firstInvalid.focus();
        return;
    }

    showAuthToast(
        authForm.id === 'register-form'
            ? 'Compte prêt à être créé par Laravel.'
            : 'Connexion prête à être envoyée à Laravel.',
    );
});

document.querySelectorAll('[data-provider]').forEach((button) => {
    button.addEventListener('click', () => {
        const provider = button.dataset.provider;
        if (provider === 'Passkey' && !window.PublicKeyCredential) {
            showAuthToast('Ce navigateur ne prend pas en charge les passkeys.');
            return;
        }

        showAuthToast(
            provider === 'Passkey'
                ? 'La vérification sécurisée par passkey s’ouvrira ici.'
                : `Redirection vers ${provider} prête à être branchée.`,
        );
    });
});
