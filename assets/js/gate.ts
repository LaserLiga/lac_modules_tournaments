import DefaultScreen from '../../../../assets/js/gate/defaultScreen';
import GateScreen from '../../../../assets/js/gate/gateScreen';

export default class TournamentScreen extends DefaultScreen {
    private interval?: ReturnType<typeof setInterval>;
    private playerAnimationTimeout?: ReturnType<typeof setTimeout>;
    private standingsAnimationTimeout?: ReturnType<typeof setTimeout>;

    private readonly playerAnimationSelectors = [
        '.player-avatar',
        '.player-title-image',
        '.player-name',
        '.category-icon',
        '.player-value strong',
    ];

    private readonly playerAnimationClasses = [
        'tournament-animate-tilt',
        'tournament-animate-scale',
        'tournament-animate-flip',
        'tournament-animate-glow',
    ];

    private readonly standingsAnimationSelectors = [
        '.standing-podium-card.rank-1',
        '.standing-podium-card .team-logo',
        '.standing-podium-card .standing-team-name',
        '.standing-podium-card .standing-points strong',
        '.standing-podium-card .standing-score strong',
        '.standing-row-card',
        '.standing-row-card .team-logo',
        '.standing-row-card .standing-team-name',
        '.standing-row-points strong',
        '.standing-row-score strong',
        '.standing-stat',
    ];

    private readonly standingsAnimationClasses = [
        'tournament-standings-animate-scale',
        'tournament-standings-animate-tilt',
        'tournament-standings-animate-glow',
        'tournament-standings-animate-pop',
    ];

	isSame(active: GateScreen): boolean {
		return false;
	}

	animateIn(): void {
		super.animateIn();

        const timer = this.content.parentElement?.querySelector<HTMLDivElement>('.timer');
		if (timer) {
			timer.classList.add('timer-tournament');
            timer.classList.toggle(
                'timer-tournament-screen',
                this.content.classList.contains('tournament-games-only')
                || this.content.classList.contains('tournament-standings-only')
                || this.content.classList.contains('tournament-players-only')
            );
		}

		// Games scroll
		this.interval = setInterval(() => {
            const gamesList = this.content.querySelector<HTMLDivElement>('.tournament-games-content-wrapper');
			if (!gamesList) {
				return;
			}

			// Find first unfinished game
            const firstNotFinishedRow = gamesList.querySelector<HTMLTableRowElement>('tr:not(.finished)');
			if (!firstNotFinishedRow) {
				return;
			}

			const midPoint = gamesList.getBoundingClientRect().y + (gamesList.getBoundingClientRect().height / 2);
			const y = firstNotFinishedRow.getBoundingClientRect().y;
			console.log(firstNotFinishedRow, midPoint, y);
			if (y > midPoint) {
				gamesList.scrollBy({top: y - midPoint, behavior: 'smooth'});
			}
		}, 5000);

        this.schedulePlayerAnimation();
        this.scheduleStandingsAnimation();
	}

	animateOut(): void {
		super.animateOut();
		if (this.interval) {
			clearInterval(this.interval);
            this.interval = undefined;
        }
        if (this.playerAnimationTimeout) {
            clearTimeout(this.playerAnimationTimeout);
            this.playerAnimationTimeout = undefined;
        }
        if (this.standingsAnimationTimeout) {
            clearTimeout(this.standingsAnimationTimeout);
            this.standingsAnimationTimeout = undefined;
		}

        const timer = this.content.parentElement?.querySelector<HTMLDivElement>('.timer');
		if (timer) {
			timer.classList.remove('timer-tournament');
            timer.classList.remove('timer-tournament-screen');
		}
	}

	showTimer(): boolean {
		return true;
	}

    private schedulePlayerAnimation(): void {
        if (!this.content.classList.contains('tournament-players-only')) {
            return;
        }

        const delay = 2000 + Math.round(Math.random() * 10000);
        this.playerAnimationTimeout = setTimeout(() => {
            this.triggerPlayerAnimation();
            this.schedulePlayerAnimation();
        }, delay);
    }

    private triggerPlayerAnimation(): void {
        const elements = this.playerAnimationSelectors
            .flatMap(selector => Array.from(this.content.querySelectorAll<HTMLElement>(selector)))
            .filter(element => !this.isPlayerAnimationActive(element));

        if (elements.length === 0) {
            return;
        }

        const element = elements[Math.floor(Math.random() * elements.length)];
        const className = this.playerAnimationClasses[Math.floor(Math.random() * this.playerAnimationClasses.length)];

        element.classList.add(className);
        const cleanup = () => element.classList.remove(className);
        element.addEventListener('animationend', cleanup, {once: true});
        setTimeout(cleanup, 1500);
    }

    private isPlayerAnimationActive(element: HTMLElement): boolean {
        return this.playerAnimationClasses.some(className => element.classList.contains(className));
    }

    private scheduleStandingsAnimation(): void {
        if (!this.content.classList.contains('tournament-standings-only')) {
            return;
        }

        const delay = 3000 + Math.round(Math.random() * 5000);
        this.standingsAnimationTimeout = setTimeout(() => {
            this.triggerStandingsAnimation();
            this.scheduleStandingsAnimation();
        }, delay);
    }

    private triggerStandingsAnimation(): void {
        const elements = this.standingsAnimationSelectors
            .flatMap(selector => Array.from(this.content.querySelectorAll<HTMLElement>(selector)))
            .filter(element => !this.isStandingsAnimationActive(element));

        if (elements.length === 0) {
            return;
        }

        const element = elements[Math.floor(Math.random() * elements.length)];
        const className = this.standingsAnimationClasses[Math.floor(Math.random() * this.standingsAnimationClasses.length)];

        element.classList.add(className);
        const cleanup = () => element.classList.remove(className);
        element.addEventListener('animationend', cleanup, {once: true});
        setTimeout(cleanup, 1500);
    }

    private isStandingsAnimationActive(element: HTMLElement): boolean {
        return this.standingsAnimationClasses.some(className => element.classList.contains(className));
    }
}
