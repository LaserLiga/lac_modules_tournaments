<?php

namespace LAC\Modules\Tournament\Services;

use App\GameModels\Game\Game;
use App\GameModels\Game\Player;
use App\GameModels\Game\Team;
use Dibi\Row;
use LAC\Modules\Core\GameDataExtensionInterface;
use LAC\Modules\Tournament\Models\Team as TournamentTeam;
use Lsr\Core\Exceptions\ModelNotFoundException;
use Lsr\Core\Exceptions\ValidationException;
use Lsr\Logging\Exceptions\DirectoryCreationException;

class TeamDataExtension implements GameDataExtensionInterface
{

	public function init(Player|Game|Team $game): void {
		$game->hook('setBonus', [$this, 'setBonus']);
	}

	/**
	 * @param Row $row
	 * @param Team $game
	 * @return void
	 * @throws ModelNotFoundException
	 * @throws ValidationException
	 * @throws DirectoryCreationException
	 */
	public function parseRow(Row $row, Player|Game|Team $game): void {
		$tournamentTeam = null;
		if (isset($row->id_tournament_team)) {
			$tournamentTeam = TournamentTeam::get((int)$row->id_tournament_team);
		}
		$game->tournamentTeam = $tournamentTeam;
	}

	/**
	 * @inheritDoc
	 * @param Team $game
	 */
	public function addQueryData(array &$data, Player|Game|Team $game): void {
		$data['id_tournament_team'] = $game->tournamentTeam?->id;
	}

	/**
	 * @inheritDoc
	 */
	public function addJsonData(array &$data, Player|Game|Team $game): void {
		if (isset($game->tournamentTeam)) {
			$data['tournamentTeam'] = $game->tournamentTeam->idPublic;
		}
	}

	public function save(Player|Game|Team $game): bool {
		if (isset($game->tournamentTeam)) {
			return $game->tournamentTeam->save();
		}
		return true;
	}

	public function setBonus(Team $team): void {
		if (isset($team->getGame()?->tournamentGame, $team->tournamentTeam)) {
			foreach ($team->getGame()->tournamentGame->teams as $tournamentTeam) {
				if ($tournamentTeam->team->id !== $team->tournamentTeam->id) {
					continue;
				}
				$tournamentTeam->score = $team->getScore();
			}
		}
	}
}