import {customFetch} from '../../../../assets/js/includes/apiClient';
import {triggerNotification, triggerNotificationError} from '../../../../assets/js/includes/notifications';
import {startLoading, stopLoading} from '../../../../assets/js/loaders';
import {Tab} from 'bootstrap';

type BracketActionResponse = {
    status: 'ok' | 'error',
    errors?: string[],
    notices?: ({ type?: string, content?: string } | string)[],
}

window.addEventListener('load', () => {
    initRozlosTabs();
    initBracketActions();
    initRozlosGenerator();
});

function initRozlosGenerator(): void {
    const form = document.getElementById('rozlos-form') as HTMLFormElement | undefined;
    if (!form) {
        return;
    }

    const teamsInGame: number = parseInt(form.dataset.teamsInGame ?? '0');
    const totalTeamCount = parseInt(form.dataset.teams ?? '0');

    const tournamentType = document.getElementById('tournament-type') as HTMLSelectElement;
    const gameRepeat = document.getElementById('game-repeat') as HTMLInputElement;
    const gameLength = document.getElementById('game-length') as HTMLInputElement;
    const gamePause = document.getElementById('game-pause') as HTMLInputElement;

    const tournamentGames = document.getElementById('tournament-games') as HTMLSpanElement;
    const tournamentLength = document.getElementById('tournament-length') as HTMLSpanElement;
    const tournamentGamesPerTeam = document.getElementById('tournament-games-per-team') as HTMLSpanElement;
    const tournamentTimePerTeam = document.getElementById('tournament-time-per-team') as HTMLSpanElement;
    const activeTeamCount = document.getElementById('active-team-count') as HTMLSpanElement;

    const gamesPerTeamInput = document.getElementById('barrage-base-games') as HTMLInputElement;
    const gamesPerTeamHelp = document.getElementById('barrage-base-games-help') as HTMLDivElement;
    const gamesPerTeamFeedback = document.getElementById('barrage-base-games-feedback') as HTMLDivElement;
    const barrageRoundsInput = document.getElementById('barrage-rounds') as HTMLInputElement;

    const barrageArgs = document.querySelector('.barrage-args') as HTMLDivElement;
    const teamToggles = Array.from(form.querySelectorAll<HTMLInputElement>('.tournament-team-toggle'));

    recalculate();

    tournamentType.addEventListener('change', recalculate);
    gameRepeat.addEventListener('change', recalculate);
    gameLength.addEventListener('input', recalculate);
    gamePause.addEventListener('input', recalculate);
    if (gamesPerTeamInput) {
        gamesPerTeamInput.addEventListener('input', recalculate);
    }
    if (barrageRoundsInput) {
        barrageRoundsInput.addEventListener('input', recalculate);
    }
    teamToggles.forEach(toggle => toggle.addEventListener('change', recalculate));

    function recalculate() {
        const teamCount = getActiveTeamCount();
        let games = 0;
        let gamesPerTeam = 0;
        let barrageRounds = 0;
        let baseRoundGroupTeamCount = 0;
        let baseGamesPerTeam = 0;
        const isBarrage = tournamentType.value === 'gBarrage' || tournamentType.value === '2gBarrage';

        if (barrageArgs) {
            barrageArgs.style.display = isBarrage ? 'block' : 'none';
        }
        if (gamesPerTeamInput) {
            gamesPerTeamInput.disabled = !isBarrage;
        }
        if (barrageRoundsInput) {
            barrageRoundsInput.disabled = !isBarrage;
        }

        switch (tournamentType.value) {
            case 'rr':
                switch (teamsInGame) {
                    case 4:
                        games = teamCount * (teamCount - 1) * (teamCount - 2) * (teamCount - 3) / 24;
                        gamesPerTeam = (teamCount - 1) * (teamCount - 2) * (teamCount - 3) / 6;
                        break;
                    case 3:
                        games = teamCount * (teamCount - 1) * (teamCount - 2) / 6;
                        gamesPerTeam = (teamCount - 1) * (teamCount - 2) / 2;
                        break;
                    default:
                        games = teamCount * (teamCount - 1) / 2;
                        gamesPerTeam = teamCount - 1;
                }
                break;
            case '2grr':
            case '2grr10':
                const half = teamCount / 2;
                gamesPerTeam = (half - 1) * 2;
                games = (half * (half - 1) / 2) * 4;
                break;
            case 'gBarrage':
                baseGamesPerTeam = getBaseGamesPerTeam();
                baseRoundGroupTeamCount = teamCount;
                const eliminationsPerGame = teamsInGame - 1;
                const preliminaryEliminations = (teamCount - 1) % eliminationsPerGame;
                barrageRounds = (teamCount - preliminaryEliminations - 1) / eliminationsPerGame;
                if (preliminaryEliminations > 0) {
                    barrageRounds++;
                }
                gamesPerTeam = baseGamesPerTeam + 1;
                games = (teamCount * baseGamesPerTeam / teamsInGame) + barrageRounds;
                break;
            case '2gBarrage':
                baseGamesPerTeam = getBaseGamesPerTeam();
                const groupTeamCount = Math.floor(teamCount / 2);
                baseRoundGroupTeamCount = groupTeamCount;
                barrageRounds = Math.min(
                    barrageRoundsInput ? barrageRoundsInput.valueAsNumber : 4,
                    Math.max(1, groupTeamCount - teamsInGame + 1)
                );
                gamesPerTeam = baseGamesPerTeam + 1;
                games = (teamCount * baseGamesPerTeam / teamsInGame) + barrageRounds + 2;
                break;
        }

        validateBaseGamesPerTeam(isBarrage, baseRoundGroupTeamCount, baseGamesPerTeam);

        const repeat = gameRepeat.valueAsNumber;
        games *= repeat;
        gamesPerTeam *= repeat;
        const length = (games * parseInt(gameLength.value)) + ((games - 1) * parseInt(gamePause.value));
        const teamLength = gamesPerTeam * parseInt(gameLength.value);

        tournamentGames.innerText = games.toString();
        tournamentLength.innerText = `${Math.floor(length / 60)}h ${length % 60}min`;
        tournamentGamesPerTeam.innerText = gamesPerTeam.toString();
        tournamentTimePerTeam.innerText = `${Math.floor(teamLength / 60)}h ${teamLength % 60}min`;
    }

    function getActiveTeamCount(): number {
        const count = teamToggles.length > 0
            ? teamToggles.filter(toggle => toggle.checked).length
            : totalTeamCount;
        if (activeTeamCount) {
            activeTeamCount.innerText = count.toString();
        }

        return count;
    }

    function getBaseGamesPerTeam(): number {
        if (!gamesPerTeamInput || Number.isNaN(gamesPerTeamInput.valueAsNumber)) {
            return 3;
        }

        return gamesPerTeamInput.valueAsNumber;
    }

    function validateBaseGamesPerTeam(isBarrage: boolean, groupTeamCount: number, baseGameCount: number): void {
        if (!gamesPerTeamInput || !gamesPerTeamFeedback) {
            return;
        }

        if (gamesPerTeamHelp && isBarrage) {
            gamesPerTeamHelp.innerText = `Podmínka: (${groupTeamCount} × ${baseGameCount}) % ${teamsInGame} === 0.`;
        }

        const invalid = isBarrage &&
            (!Number.isInteger(baseGameCount) ||
                baseGameCount < 1 ||
                (groupTeamCount * baseGameCount) % teamsInGame !== 0);
        if (!invalid) {
            gamesPerTeamInput.setCustomValidity('');
            gamesPerTeamInput.classList.remove('is-invalid');
            gamesPerTeamFeedback.innerText = '';
            return;
        }

        const message = `Podmínka (${groupTeamCount} × ${baseGameCount}) % ${teamsInGame} === 0 není splněna.`;
        gamesPerTeamInput.setCustomValidity(message);
        gamesPerTeamInput.classList.add('is-invalid');
        gamesPerTeamFeedback.innerText = message;
    }
}

function initBracketActions(): void {
    const wrapper = document.getElementById('tournament-rozlos-content');
    if (!wrapper) {
        return;
    }

    wrapper.querySelectorAll<HTMLFormElement>('form[data-ajax-rozlos]').forEach(form => {
        form.addEventListener('submit', async event => {
            event.preventDefault();

            if (!form.reportValidity()) {
                return;
            }

            const submitter = event.submitter as HTMLButtonElement | null;
            const confirmMessage = submitter?.dataset.confirm;
            if (confirmMessage && !window.confirm(confirmMessage)) {
                return;
            }

            submitter?.setAttribute('disabled', 'disabled');
            startLoading();

            try {
                const response = await customFetch(form.action, 'POST', {
                    body: new FormData(form),
                }) as BracketActionResponse;

                showBracketActionMessages(response);
                await refreshBracketContent();
                stopLoading(true);
            } catch (error) {
                stopLoading(false);
                await triggerNotificationError(error as Error);
            } finally {
                submitter?.removeAttribute('disabled');
            }
        });
    });
}

function initRozlosTabs(activeTarget?: string | null): void {
    const tabs = document.querySelectorAll<HTMLButtonElement>('#tournament-rozlos-tabs [data-bs-toggle="tab"]');
    tabs.forEach(tab => {
        tab.addEventListener('click', event => {
            event.preventDefault();
            new Tab(tab).show();
            if (tab.dataset.bsTarget) {
                history.replaceState(null, '', tab.dataset.bsTarget);
            }
        });
    });

    const target = activeTarget ?? window.location.hash;
    if (!target) {
        return;
    }

    const tab = document.querySelector<HTMLButtonElement>(
        `#tournament-rozlos-tabs [data-bs-target="${target}"]`
    );
    if (tab) {
        new Tab(tab).show();
    }
}

function showBracketActionMessages(response: BracketActionResponse): void {
    response.notices?.forEach(notice => {
        if (typeof notice === 'string') {
            triggerNotification({type: 'success', content: notice});
            return;
        }

        triggerNotification({
            type: notice.type ?? 'success',
            content: notice.content ?? '',
        });
    });

    response.errors?.forEach(error => {
        triggerNotification({type: 'danger', content: error});
    });
}

async function refreshBracketContent(): Promise<void> {
    const activeTab = document.querySelector<HTMLButtonElement>('#tournament-rozlos-tabs .nav-link.active');
    const activeTarget = activeTab?.dataset.bsTarget ?? null;
    const response = await fetch(window.location.href, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    if (!response.ok) {
        throw new Error(response.statusText);
    }

    const html = await response.text();
    const documentNew = new DOMParser().parseFromString(html, 'text/html');
    const wrapper = document.getElementById('tournament-rozlos-content');
    const wrapperNew = documentNew.getElementById('tournament-rozlos-content');
    if (!wrapper || !wrapperNew) {
        throw new Error('Tournament bracket content was not found in the response.');
    }

    wrapper.replaceWith(wrapperNew);
    initRozlosTabs(activeTarget);
    initBracketActions();
    initRozlosGenerator();
    window.dispatchEvent(new CustomEvent('tournament:bracket-updated'));
}
