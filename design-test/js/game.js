const params = new URLSearchParams(window.location.search);
const requestedName = params.get('name')?.trim().slice(0, 24);
const currentPlayerName = requestedName || 'Marty';

const players = [
    { name: 'Sofia', score: 1350, avatar: 'pink' },
    { name: 'Théo', score: 1280, avatar: 'yellow' },
    { name: 'Inès', score: 1140, avatar: 'cyan' },
    { name: 'Lucas', score: 980, avatar: 'green' },
    { name: 'Jade', score: 920, avatar: 'lavender' },
    { name: currentPlayerName, score: 860, avatar: 'coral', isCurrent: true },
    { name: 'Nora', score: 810, avatar: 'coral' },
    { name: 'Sam', score: 720, avatar: 'cyan' },
    { name: 'Léo', score: 640, avatar: 'yellow' },
    { name: 'Maya', score: 590, avatar: 'pink' },
    { name: 'Noé', score: 470, avatar: 'green' },
    { name: 'Lou', score: 390, avatar: 'lavender' },
];

const playerStrip = document.querySelector('#player-strip');
const currentScore = document.querySelector('#current-score');
const pauseButton = document.querySelector('#pause-game');
const pauseOverlay = document.querySelector('#pause-overlay');
const resumeButton = document.querySelector('#resume-game');
const answerForm = document.querySelector('#answer-form');
const answerInput = document.querySelector('#answer');
const answerError = document.querySelector('#answer-error');
const toast = document.querySelector('#game-toast');
const timerOutputs = document.querySelectorAll('[data-round-timer]');
const timerBadge = document.querySelector('#round-timer-badge');
const cooldown = document.querySelector('#round-cooldown');
const movieFrame = document.querySelector('#movie-frame');
const movieFrameStatus = document.querySelector('#movie-frame-status');
const roundDuration = 30;
const roundDurationMilliseconds = roundDuration * 1000;
const movieFrames = ['../movie/movie-1.webp', '../movie/movie-2.webp', '../movie/movie-3.webp'];
let toastTimer;
let remainingTimeMilliseconds = roundDurationMilliseconds;
let roundTimer = roundDuration;
let currentMovieFrameIndex = -1;
let currentColorState;
let lastDisplayedSecond;
let previousAnimationTime;
let isPaused = false;

function formatScore(score) {
    return new Intl.NumberFormat('fr-FR').format(score);
}

function createAvatar(variant) {
    const avatar = document.createElement('span');
    avatar.className = `player-avatar player-avatar--${variant}`;
    avatar.setAttribute('aria-hidden', 'true');
    avatar.innerHTML = '<span class="player-avatar__mouth"></span>';
    return avatar;
}

function renderPlayers() {
    playerStrip.replaceChildren();

    players.forEach((player) => {
        const card = document.createElement('article');
        card.className = `game-player${player.isCurrent ? ' game-player--current' : ''}`;
        card.setAttribute('aria-label', `${player.name}, ${formatScore(player.score)} points`);

        const seat = document.createElement('div');
        seat.className = 'game-player__seat';
        seat.innerHTML = `
            <span class="game-player__seat-back" aria-hidden="true"></span>
            <span class="game-player__seat-cushion" aria-hidden="true"></span>
            <span class="game-player__seat-arm game-player__seat-arm--left" aria-hidden="true"></span>
            <span class="game-player__seat-arm game-player__seat-arm--right" aria-hidden="true"></span>
        `;
        seat.appendChild(createAvatar(player.avatar));

        const name = document.createElement('strong');
        name.className = 'game-player__name';
        name.textContent = player.name;
        seat.appendChild(name);
        card.appendChild(seat);

        const score = document.createElement('span');
        score.className = 'game-player__score';
        score.textContent = `${formatScore(player.score)} pts`;
        card.appendChild(score);

        playerStrip.appendChild(card);
    });

    const currentPlayer = players.find((player) => player.isCurrent);
    currentScore.innerHTML = `<img class="icon" src="../svg/icons/star.svg" alt="" aria-hidden="true"><span>${formatScore(currentPlayer.score)} points</span>`;
}

function showToast(message) {
    toast.textContent = message;
    toast.classList.add('toast--visible');
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(() => toast.classList.remove('toast--visible'), 2500);
}

function updateRoundDisplay() {
    roundTimer = Math.ceil(remainingTimeMilliseconds / 1000);
    const progress = (remainingTimeMilliseconds / roundDurationMilliseconds) * 100;
    const colorState = roundTimer >= 20 ? 'green' : roundTimer >= 10 ? 'orange' : 'red';
    const frameIndex = roundTimer >= 20 ? 0 : roundTimer >= 10 ? 1 : 2;

    if (roundTimer !== lastDisplayedSecond) {
        timerOutputs.forEach((output) => {
            output.textContent = String(roundTimer);
        });
        timerBadge.setAttribute('aria-label', `${roundTimer} secondes restantes`);
        lastDisplayedSecond = roundTimer;
    }

    cooldown.style.setProperty('--cooldown-progress', `${progress}%`);

    if (colorState !== currentColorState) {
        cooldown.classList.remove(
            'game-screen__cooldown--green',
            'game-screen__cooldown--orange',
            'game-screen__cooldown--red',
        );
        cooldown.classList.add(`game-screen__cooldown--${colorState}`);
        currentColorState = colorState;
    }

    if (frameIndex !== currentMovieFrameIndex) {
        currentMovieFrameIndex = frameIndex;
        movieFrame.src = movieFrames[frameIndex];
        movieFrame.alt = `Indice visuel ${frameIndex + 1} sur ${movieFrames.length} du film`;
        movieFrameStatus.textContent = `Image ${frameIndex + 1} / ${movieFrames.length}`;
    }
}

function animateRound(timestamp) {
    if (previousAnimationTime === undefined) previousAnimationTime = timestamp;

    const elapsedTime = timestamp - previousAnimationTime;
    previousAnimationTime = timestamp;

    if (!isPaused && remainingTimeMilliseconds > 0) {
        remainingTimeMilliseconds = Math.max(0, remainingTimeMilliseconds - elapsedTime);
        updateRoundDisplay();
    }

    if (remainingTimeMilliseconds > 0) window.requestAnimationFrame(animateRound);
}

function setPaused(paused) {
    isPaused = paused;
    pauseOverlay.hidden = !paused;
    pauseButton.setAttribute('aria-pressed', String(paused));
    pauseButton.querySelector('.pause-button__label').textContent = paused ? 'Reprendre' : 'Pause';

    if (paused) {
        resumeButton.focus();
    } else {
        pauseButton.focus();
    }
}

pauseButton.addEventListener('click', () => setPaused(!isPaused));
resumeButton.addEventListener('click', () => setPaused(false));

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && isPaused) setPaused(false);
});

answerInput.addEventListener('input', () => {
    answerInput.removeAttribute('aria-invalid');
    answerError.textContent = '';
});

answerForm.addEventListener('submit', (event) => {
    event.preventDefault();
    const answer = answerInput.value.trim();

    if (!answer) {
        answerInput.setAttribute('aria-invalid', 'true');
        answerError.textContent = 'Écrivez une réponse.';
        answerInput.focus();
        return;
    }

    answerInput.value = '';
    showToast('Réponse envoyée !');
});

movieFrames.forEach((source) => {
    const image = new Image();
    image.src = source;
});

renderPlayers();
updateRoundDisplay();
window.requestAnimationFrame(animateRound);
