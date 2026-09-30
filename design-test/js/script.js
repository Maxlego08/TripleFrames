const joinForm = document.querySelector('#join-form');
const nicknameInput = document.querySelector('#nickname');
const roomCodeInput = document.querySelector('#room-code');
const nicknameError = document.querySelector('#nickname-error');
const roomCodeError = document.querySelector('#room-code-error');
const toast = document.querySelector('#toast');

let toastTimer;

function showToast(message) {
    toast.textContent = message;
    toast.classList.add('toast--visible');
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(() => {
        toast.classList.remove('toast--visible');
    }, 3200);
}

function setFieldError(input, errorNode, message = '') {
    input.setAttribute('aria-invalid', message ? 'true' : 'false');
    errorNode.textContent = message;
}

roomCodeInput.addEventListener('input', () => {
    const normalized = roomCodeInput.value
        .toUpperCase()
        .replace(/[^A-Z0-9]/g, '');
    roomCodeInput.value = normalized;
    setFieldError(roomCodeInput, roomCodeError);
});

nicknameInput.addEventListener('input', () => {
    setFieldError(nicknameInput, nicknameError);
});

joinForm.addEventListener('submit', (event) => {
    event.preventDefault();

    const nickname = nicknameInput.value.trim();
    const roomCode = roomCodeInput.value.trim();
    let isValid = true;

    if (nickname.length < 2) {
        setFieldError(
            nicknameInput,
            nicknameError,
            'Entre un pseudo d’au moins 2 caractères.',
        );
        isValid = false;
    }

    if (roomCode.length < 4) {
        setFieldError(
            roomCodeInput,
            roomCodeError,
            'Entre le code affiché par l’hôte.',
        );
        isValid = false;
    }

    if (!isValid) {
        joinForm.querySelector('[aria-invalid="true"]')?.focus();
        return;
    }

    showToast(`Prêt à rejoindre ${roomCode} avec le pseudo ${nickname} !`);
    window.setTimeout(() => {
        window.location.href = `waiting-room.html?host=0&name=${encodeURIComponent(nickname)}&code=${encodeURIComponent(roomCode)}`;
    }, 450);
});

document.querySelector('#create-game').addEventListener('click', () => {
    window.location.href = 'waiting-room.html?host=1&name=Marty';
});

const params = new URLSearchParams(window.location.search);
document.body.dataset.authenticated = params.get('connected') === '1' ? 'true' : 'false';
