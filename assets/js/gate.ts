import GateScreen from '../../../../assets/js/gate/gateScreen';

export default class TournamentScreen implements GateScreen {
	content: HTMLDivElement;
	private removePreviousContent: () => void;
	private interval: NodeJS.Timeout;

	init(content: HTMLDivElement, removePreviousContent: () => void): void {
		this.content = content;
		this.removePreviousContent = removePreviousContent;

		// Games scroll
		this.interval = setInterval(() => {
			const gamesList: HTMLDivElement = this.content.querySelector('.tournament-games-content-wrapper');
			if (!gamesList) {
				return;
			}

			// Find first unfinished game
			const firstNotFinishedRow: HTMLTableRowElement = this.content.querySelector('.tournament-games-content-wrapper').querySelector('tr:not(.finished)');
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
	}

	isSame(active: GateScreen): boolean {
		return false;
	}

	animateIn(): void {
		this.content.classList.add('content', 'in');

		setTimeout(() => {
			this.removePreviousContent();
			this.content.classList.remove('in');
		}, 2000);
	}

	animateOut(): void {
		this.content.classList.add('out');
		clearInterval(this.interval);
	}

	showTimer(): boolean {
		return true;
	}
}