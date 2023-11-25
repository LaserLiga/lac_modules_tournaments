import EventServerInstance from "../../../../assets/js/EventServer";
import {loadContent, tipsRotations} from "../../../../assets/js/components/gate";
import {gameTimer} from "../../../../assets/js/functions";

export let reloadTimeout: { timeout: null | ReturnType<typeof setTimeout> } = {timeout: null};
window.addEventListener('load', () => {

	if (reloadTimer && reloadTimer > 0) {
		reloadTimeout.timeout = setTimeout(() => {
			loadContent(window.location.pathname, reloadTimeout);
		}, reloadTimer * 1000);
	}

	// WebSocket event listener
	EventServerInstance.addEventListener(['game-imported', 'gate-reload'], () => {
		loadContent(window.location.pathname, reloadTimeout);
	});

	tipsRotations();
    gameTimer();

    setInterval(() => {
        const gamesList: HTMLDivElement = document.querySelector('.tournament-games-content-wrapper');
        if (!gamesList) {
            return;
        }

        // Find first unfinished game
        const firstNotFinishedRow: HTMLTableRowElement = document.querySelector('.tournament-games-content-wrapper').querySelector('tr:not(.finished)');
        if (!firstNotFinishedRow) {
            return;
        }

        const midPoint = gamesList.getBoundingClientRect().y + (gamesList.getBoundingClientRect().height / 2);
        const y = firstNotFinishedRow.getBoundingClientRect().y;
        console.log(firstNotFinishedRow, midPoint, y)
        if (y > midPoint) {
            gamesList.scrollBy({top: y - midPoint, behavior: 'smooth'});
        }
    }, 5000);
});