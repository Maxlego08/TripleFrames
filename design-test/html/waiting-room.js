const params = new URLSearchParams(window.location.search);
const requestedCode = params.get('code')?.toUpperCase().replace(/[^A-Z0-9]/g, '');
const roomCodeValue = requestedCode?.length >= 4 ? requestedCode.slice(0, 8) : 'M8K2';
const isHost = params.get('host') !== '0';
const requestedName = params.get('name')?.trim().slice(0, 24);
const currentPlayerName = requestedName || (isHost ? 'Marty' : 'Camille');

const players = [
    { name: 'Sofia', avatar: 'pink' },
    null,
    { name: 'Théo', avatar: 'yellow' },
    { name: 'Inès', avatar: 'cyan' },
    null,
    { name: 'Lucas', avatar: 'green' },
    { name: 'Jade', avatar: 'lavender' },
    null,
    { name: 'Nora', avatar: 'coral' },
    null,
    null,
];

const avatarVariants = ['coral', 'cyan', 'yellow', 'lavender', 'green', 'pink'];
let currentAvatarIndex = 0;
let codeIsVisible = false;
let toastTimer;

const roomCode = document.querySelector('#room-code');
const toggleCode = document.querySelector('#toggle-code');
const toggleCodeLabel = document.querySelector('#toggle-code-label');
const seatList = document.querySelector('#seat-list');
const playerCount = document.querySelector('#player-count');
const avatar = document.querySelector('#current-avatar');
const playerName = document.querySelector('#current-player-name');
const toast = document.querySelector('#waiting-toast');
const startButton = document.querySelector('#start-game');
const settingsDialog = document.querySelector('#settings-dialog');

function showToast(message) {
    toast.textContent = message;
    toast.classList.add('toast--visible');
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(() => {
        toast.classList.remove('toast--visible');
    }, 3000);
}

function createAvatar(variant) {
    const avatarNode = document.createElement('span');
    avatarNode.className = `player-avatar player-avatar--${variant}`;
    avatarNode.setAttribute('aria-hidden', 'true');

    const spark = document.createElement('span');
    spark.className = 'player-avatar__spark';
    spark.textContent = '✦';
    avatarNode.appendChild(spark);
    return avatarNode;
}

function renderSeats() {
    seatList.replaceChildren();

    players.forEach((player, index) => {
        const seat = document.createElement('div');
        seat.className = `table-seat table-seat--position-${index + 1}`;

        if (player) {
            seat.classList.add('table-seat--occupied');
            seat.setAttribute('aria-label', `${player.name} a rejoint la partie`);
            seat.appendChild(createAvatar(player.avatar));

            const name = document.createElement('span');
            name.className = 'table-seat__name';
            name.textContent = player.name;
            seat.appendChild(name);
        } else {
            seat.classList.add('table-seat--empty');
            seat.setAttribute('aria-label', 'Siège libre');

            const chair = document.createElement('span');
            chair.className = 'table-seat__chair';
            chair.setAttribute('aria-hidden', 'true');
            seat.appendChild(chair);
        }
        seatList.appendChild(seat);
    });

    const occupiedSeats = players.filter(Boolean).length + 1;
    playerCount.textContent = `${occupiedSeats} / 12 joueurs`;
}

function updateCurrentAvatar() {
    const variant = avatarVariants[currentAvatarIndex];
    avatar.className = `player-avatar player-avatar--${variant} player-avatar--large`;
    avatar.innerHTML = '<span class="player-avatar__spark">✦</span>';
}

function updateCodeVisibility() {
    roomCode.textContent = codeIsVisible ? roomCodeValue : '****';
    toggleCode.setAttribute('aria-pressed', String(codeIsVisible));
    toggleCodeLabel.textContent = codeIsVisible ? 'Masquer' : 'Afficher';
}

async function copyRoomCode() {
    try {
        await navigator.clipboard.writeText(roomCodeValue);
    } catch {
        const textArea = document.createElement('textarea');
        textArea.value = roomCodeValue;
        textArea.style.position = 'fixed';
        textArea.style.opacity = '0';
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        textArea.remove();
    }

    showToast(`Code ${roomCodeValue} copié !`);
}

document.querySelector('#copy-code').addEventListener('click', copyRoomCode);

toggleCode.addEventListener('click', () => {
    codeIsVisible = !codeIsVisible;
    updateCodeVisibility();
});

document.querySelector('#previous-avatar').addEventListener('click', () => {
    currentAvatarIndex = (currentAvatarIndex - 1 + avatarVariants.length) % avatarVariants.length;
    updateCurrentAvatar();
});

document.querySelector('#next-avatar').addEventListener('click', () => {
    currentAvatarIndex = (currentAvatarIndex + 1) % avatarVariants.length;
    updateCurrentAvatar();
});

document.querySelector('#leave-game').addEventListener('click', () => {
    window.location.href = 'index.html';
});

startButton.addEventListener('click', () => {
    if (isHost) showToast('La partie va commencer !');
});

document.querySelector('#open-settings').addEventListener('click', () => {
    settingsDialog.showModal();
});

document.querySelector('#close-settings').addEventListener('click', () => {
    settingsDialog.close();
});

document.querySelector('#confirm-settings').addEventListener('click', () => {
    settingsDialog.close();
});

settingsDialog.addEventListener('click', (event) => {
    if (event.target === settingsDialog) settingsDialog.close();
});

playerName.textContent = currentPlayerName;
startButton.disabled = !isHost;
if (!isHost) startButton.querySelector('span:first-child').textContent = 'En attente de l’hôte';

renderSeats();
updateCodeVisibility();
