window.addEventListener('load', () => {
    const form = document.getElementById('rozlos-form') as HTMLFormElement | undefined;
    if (!form) {
        return;
    }

    const teamsInGame: number = parseInt(form.dataset.teamsInGame);
    const teamCount = parseInt(form.dataset.teams);

    const tournamentType = document.getElementById('tournament-type') as HTMLSelectElement;
    const gameRepeat = document.getElementById('game-repeat') as HTMLInputElement;
    const gameLength = document.getElementById('game-length') as HTMLInputElement;
    const gamePause = document.getElementById('game-pause') as HTMLInputElement;

    const tournamentGames = document.getElementById('tournament-games') as HTMLSpanElement;
    const tournamentLength = document.getElementById('tournament-length') as HTMLSpanElement;
    const tournamentGamesPerTeam = document.getElementById('tournament-games-per-team') as HTMLSpanElement;
    const tournamentTimePerTeam = document.getElementById('tournament-time-per-team') as HTMLSpanElement;

    const gamesPerTeamInput = document.getElementById('barrage-base-games') as HTMLInputElement;
    const barrageRoundsInput = document.getElementById('barrage-rounds') as HTMLInputElement;

    const barrageArgs = document.querySelector('.barrage-args') as HTMLDivElement;

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
    if (barrageArgs) {
        tournamentType.addEventListener('change', () => {
            if (tournamentType.value === 'gBarrage' || tournamentType.value === '2gBarrage') {
                barrageArgs.style.display = 'block';
            } else {
                barrageArgs.style.display = 'none';
            }
        });
    }

    function recalculate() {
        let games = 0;
        let gamesPerTeam = 0;
        let barrageRounds = 0;
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
                gamesPerTeam = (gamesPerTeamInput ? gamesPerTeamInput.valueAsNumber : 3) + 1;
                barrageRounds = Math.min(
                    barrageRoundsInput ? barrageRoundsInput.valueAsNumber : 4,
                    Math.floor(1 + ((teamCount - teamsInGame) / (teamsInGame - 1)))
                );
                games = teamCount + barrageRounds;
                break;
            case '2gBarrage':
                gamesPerTeam = (gamesPerTeamInput ? gamesPerTeamInput.valueAsNumber : 3) + 1;
                barrageRounds = Math.min(
                    barrageRoundsInput ? barrageRoundsInput.valueAsNumber : 4,
                    Math.floor(1 + ((teamCount - teamsInGame) / (teamsInGame - 1)))
                );
                games = teamCount + barrageRounds + 2;
                break;
        }

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
});