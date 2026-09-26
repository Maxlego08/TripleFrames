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
    null,
    { name: 'Inès', avatar: 'cyan' },
    null,
    { name: 'Lucas', avatar: 'green' },
    { name: 'Jade', avatar: 'lavender' },
    null,
    { name: currentPlayerName, avatar: 'coral', isCurrent: true },
    { name: 'Nora', avatar: 'coral' },
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
const toast = document.querySelector('#waiting-toast');
const startButton = document.querySelector('#start-game');
const settingsDialog = document.querySelector('#settings-dialog');
const cinemaRoom = document.querySelector('.cinema-room');
let avatar;

function showToast(message) {
    toast.textContent = message;
    toast.classList.add('toast--visible');
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(() => {
        toast.classList.remove('toast--visible');
    }, 3000);
}

function createAvatar(variant, isCurrent = false) {
    const avatarNode = document.createElement('span');
    avatarNode.className = `player-avatar player-avatar--${variant}${isCurrent ? ' player-avatar--large' : ''}`;
    avatarNode.setAttribute('aria-hidden', 'true');
    if (isCurrent) avatarNode.id = 'current-avatar';

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
        seat.className = `cinema-seat cinema-seat--position-${index + 1}`;

        const chair = document.createElement('span');
        chair.className = 'cinema-seat__chair';
        chair.setAttribute('aria-hidden', 'true');
        chair.innerHTML = `
            <span class="cinema-seat__back"></span>
            <span class="cinema-seat__cushion"></span>
            <span class="cinema-seat__arm cinema-seat__arm--left"></span>
            <span class="cinema-seat__arm cinema-seat__arm--right"></span>
        `;
        seat.appendChild(chair);

        if (player) {
            seat.classList.add('cinema-seat--occupied');
            seat.setAttribute('aria-label', `${player.name} a rejoint la partie`);

            const occupant = document.createElement('div');
            occupant.className = 'cinema-seat__occupant';

            if (player.isCurrent) {
                seat.classList.add('cinema-seat--current');
                seat.setAttribute('aria-label', `${player.name} a rejoint la partie`);

                const previousButton = document.createElement('button');
                previousButton.className = 'cinema-seat__arrow cinema-seat__arrow--previous';
                previousButton.id = 'previous-avatar';
                previousButton.type = 'button';
                previousButton.setAttribute('aria-label', 'Avatar précédent');
                previousButton.textContent = '←';
                occupant.appendChild(previousButton);
                occupant.appendChild(createAvatar(player.avatar, true));

                const nextButton = document.createElement('button');
                nextButton.className = 'cinema-seat__arrow cinema-seat__arrow--next';
                nextButton.id = 'next-avatar';
                nextButton.type = 'button';
                nextButton.setAttribute('aria-label', 'Avatar suivant');
                nextButton.textContent = '→';
                occupant.appendChild(nextButton);
            } else {
                occupant.appendChild(createAvatar(player.avatar));
            }

            const name = document.createElement('strong');
            name.className = 'cinema-seat__name';
            name.textContent = player.name;
            if (player.isCurrent) name.id = 'current-player-name';
            occupant.appendChild(name);

            seat.appendChild(occupant);
        } else {
            seat.classList.add('cinema-seat--empty');
            seat.setAttribute('aria-label', 'Siège libre');
        }
        seatList.appendChild(seat);
    });

    const occupiedSeats = players.filter(Boolean).length;
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

    showToast('Code de la partie copié !');
}

document.querySelector('#copy-code').addEventListener('click', copyRoomCode);

toggleCode.addEventListener('click', () => {
    codeIsVisible = !codeIsVisible;
    updateCodeVisibility();
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

if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    cinemaRoom.addEventListener('pointermove', (event) => {
        const bounds = cinemaRoom.getBoundingClientRect();
        const horizontalPosition = (event.clientX - bounds.left) / bounds.width - 0.5;
        const verticalPosition = (event.clientY - bounds.top) / bounds.height - 0.5;

        cinemaRoom.style.setProperty('--cinema-rotate-x', `${(-verticalPosition * 1.6).toFixed(2)}deg`);
        cinemaRoom.style.setProperty('--cinema-rotate-y', `${(horizontalPosition * 2.2).toFixed(2)}deg`);
    });

    cinemaRoom.addEventListener('pointerleave', () => {
        cinemaRoom.style.setProperty('--cinema-rotate-x', '0deg');
        cinemaRoom.style.setProperty('--cinema-rotate-y', '0deg');
    });
}

startButton.disabled = !isHost;
if (!isHost) startButton.querySelector('span:first-child').textContent = 'En attente de l’hôte';

renderSeats();
avatar = document.querySelector('#current-avatar');

document.querySelector('#previous-avatar').addEventListener('click', () => {
    currentAvatarIndex = (currentAvatarIndex - 1 + avatarVariants.length) % avatarVariants.length;
    updateCurrentAvatar();
});

document.querySelector('#next-avatar').addEventListener('click', () => {
    currentAvatarIndex = (currentAvatarIndex + 1) % avatarVariants.length;
    updateCurrentAvatar();
});

updateCodeVisibility();
