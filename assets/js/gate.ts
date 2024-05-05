import DefaultScreen from '../../../../assets/js/gate/defaultScreen';
import GateScreen from '../../../../assets/js/gate/gateScreen';

export default class TournamentScreen extends DefaultScreen {
	private interval: NodeJS.Timeout;

	isSame(active: GateScreen): boolean {
		return false;
	}

	animateIn(): void {
		super.animateIn();

		const timer = this.content.parentElement.querySelector<HTMLDivElement>('.timer');
		if (timer) {
			timer.classList.add('timer-tournament');
		}

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

	animateOut(): void {
		super.animateOut();
		if (this.interval) {
			clearInterval(this.interval);
		}

		const timer = this.content.parentElement.querySelector<HTMLDivElement>('.timer');
		if (timer) {
			timer.classList.remove('timer-tournament');
		}
	}

	showTimer(): boolean {
		return true;
	}
}