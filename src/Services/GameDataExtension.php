<?php

namespace LAC\Modules\Tournament\Services;

use App\GameModels\Game\Game;
use App\GameModels\Game\Player;
use App\GameModels\Game\Team;
use Dibi\Row;
use LAC\Modules\Core\GameDataExtensionInterface;
use LAC\Modules\Tournament\Models\Game as TournamentGame;
use LAC\Modules\Tournament\Models\GameTeam;

class GameDataExtension implements GameDataExtensionInterface
{

	public function init(Game|Team|Player $game): void {
		$game->hook('reorder', [$this, 'reorder']);
	}

	public function parseRow(Row $row, Game|Team|Player $game): void {
		$game->tournamentGame = TournamentGame::query()->where('[code] = %s', $game->code)->first();
	}

	/**
	 * @inheritDoc
	 */
	public function addQueryData(array &$data, Game|Team|Player $game): void {
	}

	/**
	 * @inheritDoc
	 */
	public function addJsonData(array &$data, Game|Team|Player $game): void {
	}

	public function save(Game|Team|Player $game): bool {
		if (isset($game->tournamentGame)) {
			$game->tournamentGame->code = $game->code;
			return $game->tournamentGame->save();
		}
		return true;
	}

	public function reorder(Game $game): void {
		if (!isset($game->tournamentGame, $game->mode)) {
			return;
		}
		$win = $game->mode->getWin($game);
		/** @var Team $team */
		foreach ($game->getTeams() as $team) {
			/** @var GameTeam $tournamentTeam */
			foreach ($game->tournamentGame->teams as $tournamentTeam) {
				if ($team->tournamentTeam->id !== $tournamentTeam->team->id) {
					continue;
				}
				$tournamentTeam->team->points -= $tournamentTeam->points;
				$tournamentTeam->score = $team->getScore();
				$tournamentTeam->position = $team->position;
				if (!isset($win)) {
					$tournamentTeam->points = $team->tournamentTeam->tournament->points->draw;
				}
				else if ($win === $team) {
					$tournamentTeam->points = $team->tournamentTeam->tournament->points->win;
				}
				else {
					$tournamentTeam->points = $team->tournamentTeam->tournament->points->loss;
				}
				$tournamentTeam->team->points += $tournamentTeam->points;
				break;
			}
		}
	}
}