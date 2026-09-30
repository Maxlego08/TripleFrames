const authForms = document.querySelectorAll('.auth-form');
const authToast = document.querySelector('#auth-toast');
let authToastTimer;

function showAuthToast(message) {
    if (!authToast) return;

    authToast.textContent = message;
    authToast.classList.add('toast--visible');
    window.clearTimeout(authToastTimer);
    authToastTimer = window.setTimeout(() => {
        authToast.classList.remove('toast--visible');
    }, 3600);
}

function setAuthError(input, errorId, message = '') {
    const errorNode = document.querySelector(`#${errorId}`);
    input?.setAttribute('aria-invalid', message ? 'true' : 'false');
    if (errorNode) errorNode.textContent = message;
}

function clearDescribedError(input) {
    const errorId = input
        .getAttribute('aria-describedby')
        ?.split(' ')
        .find((id) => id.endsWith('-error'));

    if (errorId) setAuthError(input, errorId);
}

function isEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

function revealStatus(id) {
    const status = document.querySelector(`#${id}`);
    if (status) status.hidden = false;
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
    input.addEventListener('input', () => clearDescribedError(input));
});

const registerPasswordInput = document.querySelector('#register-form #password');
const passwordMeter = document.querySelector('.password-meter');

registerPasswordInput?.addEventListener('input', () => {
    const value = registerPasswordInput.value;
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

const otpInputs = [...document.querySelectorAll('[data-otp-input]')];
const otpValue = document.querySelector('#two-factor-code');

function syncOtpValue() {
    if (!otpValue) return;
    otpValue.value = otpInputs.map((input) => input.value).join('');
}

function setOtpError(message = '') {
    otpInputs.forEach((input) => input.setAttribute('aria-invalid', message ? 'true' : 'false'));
    const errorNode = document.querySelector('#code-error');
    if (errorNode) errorNode.textContent = message;
}

otpInputs.forEach((input, index) => {
    input.addEventListener('input', () => {
        input.value = input.value.replace(/\D/g, '').slice(-1);
        setOtpError();
        syncOtpValue();

        if (input.value && otpInputs[index + 1]) {
            otpInputs[index + 1].focus();
        }
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Backspace' && !input.value && otpInputs[index - 1]) {
            otpInputs[index - 1].focus();
        }
    });

    input.addEventListener('paste', (event) => {
        const digits = event.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
        if (!digits) return;

        event.preventDefault();
        otpInputs.forEach((otpInput, otpIndex) => {
            otpInput.value = digits[otpIndex] ?? '';
        });
        syncOtpValue();
        setOtpError();
        otpInputs[Math.min(digits.length, otpInputs.length) - 1]?.focus();
    });
});

document.querySelectorAll('[data-two-factor-toggle]').forEach((toggle) => {
    toggle.addEventListener('click', () => {
        const form = document.querySelector('#two-factor-form');
        const targetMode = toggle.dataset.twoFactorToggle;
        if (!form || !targetMode) return;

        form.dataset.twoFactorMode = targetMode;
        form.querySelectorAll('[data-two-factor-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.twoFactorPanel !== targetMode;
        });

        setOtpError();
        const recoveryInput = form.elements.namedItem('recovery_code');
        if (recoveryInput) setAuthError(recoveryInput, 'recovery-code-error');

        if (targetMode === 'recovery') {
            recoveryInput?.focus();
        } else {
            otpInputs[0]?.focus();
        }
    });
});

function validateEmail(form) {
    const email = form.elements.namedItem('email');
    if (!email || isEmail(email.value.trim())) return null;

    setAuthError(email, 'email-error', 'Entre une adresse e-mail valide.');
    return email;
}

function validatePassword(password, minimumLength = 1) {
    if (!password || password.value.length >= minimumLength) return null;

    setAuthError(
        password,
        'password-error',
        minimumLength > 1 ? `Utilise au moins ${minimumLength} caractères.` : 'Entre ton mot de passe.',
    );
    return password;
}

authForms.forEach((form) => {
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const formType = form.dataset.authForm || form.id.replace('-form', '');
        let firstInvalid = null;

        if (formType === 'login' || formType === 'forgot-password' || formType === 'register') {
            firstInvalid ??= validateEmail(form);
        }

        if (formType === 'login' || formType === 'confirm-password') {
            firstInvalid ??= validatePassword(form.elements.namedItem('password'));
        }

        if (formType === 'register') {
            const name = form.elements.namedItem('name');
            const password = form.elements.namedItem('password');
            const confirmation = form.elements.namedItem('password_confirmation');
            const terms = form.elements.namedItem('terms');

            if (name.value.trim().length < 3) {
                setAuthError(name, 'name-error', 'Choisis un pseudo d’au moins 3 caractères.');
                firstInvalid ??= name;
            }

            firstInvalid ??= validatePassword(password, 8);

            if (!confirmation.value || confirmation.value !== password.value) {
                setAuthError(confirmation, 'password-confirmation-error', 'Les deux mots de passe doivent être identiques.');
                firstInvalid ??= confirmation;
            }

            if (!terms.checked) {
                setAuthError(terms, 'terms-error', 'Accepte les conditions pour créer ton compte.');
                firstInvalid ??= terms;
            }
        }

        if (formType === 'reset-password') {
            const password = form.elements.namedItem('password');
            const confirmation = form.elements.namedItem('password_confirmation');

            firstInvalid ??= validatePassword(password, 8);
            if (!confirmation.value || confirmation.value !== password.value) {
                setAuthError(confirmation, 'password-confirmation-error', 'Les deux mots de passe doivent être identiques.');
                firstInvalid ??= confirmation;
            }
        }

        if (formType === 'two-factor') {
            if (form.dataset.twoFactorMode === 'recovery') {
                const recoveryCode = form.elements.namedItem('recovery_code');
                if (!recoveryCode.value.trim()) {
                    setAuthError(recoveryCode, 'recovery-code-error', 'Entre un code de récupération.');
                    firstInvalid ??= recoveryCode;
                }
            } else {
                syncOtpValue();
                if (otpValue.value.length !== 6) {
                    setOtpError('Saisis les six chiffres du code.');
                    firstInvalid ??= otpInputs.find((input) => !input.value) || otpInputs[0];
                }
            }
        }

        if (firstInvalid) {
            firstInvalid.focus();
            return;
        }

        const successMessages = {
            login: 'Connexion prête à être envoyée à Laravel.',
            register: 'Compte prêt à être créé par Laravel.',
            'forgot-password': 'Lien de réinitialisation envoyé.',
            'reset-password': 'Nouveau mot de passe prêt à être enregistré.',
            'verify-email': 'Nouveau lien de vérification envoyé.',
            'confirm-password': 'Identité confirmée pour cette zone protégée.',
            'two-factor': 'Code prêt à être vérifié par Laravel.',
        };

        if (formType === 'forgot-password') revealStatus('forgot-password-status');
        if (formType === 'verify-email') revealStatus('verify-email-status');
        showAuthToast(successMessages[formType] || 'Formulaire prêt à être envoyé à Laravel.');
    });
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
