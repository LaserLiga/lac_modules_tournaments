<?php

namespace LAC\Modules\Tournament\Services;

use App\GameModels\Game\Game;
use App\GameModels\Game\Player;
use App\GameModels\Game\Team;
use Dibi\Row;
use LAC\Modules\Core\GameDataExtensionInterface;
use LAC\Modules\Tournament\Models\Player as TournamentPlayer;

class PlayerDataExtension implements GameDataExtensionInterface
{

	public function init(Player|Game|Team $game): void {
	}

	public function parseRow(Row $row, Player|Game|Team $game): void {
		$player = null;
		if (isset($row->id_tournament_player)) {
			$player = TournamentPlayer::get((int)$row->id_tournament_player);
		}
		$game->tournamentPlayer = $player;
	}

	/**
	 * @inheritDoc
	 */
	public function addQueryData(array &$data, Player|Game|Team $game): void {
		$data['id_tournament_player'] = $game->tournamentPlayer?->id;
	}

	/**
	 * @inheritDoc
	 */
	public function addJsonData(array &$data, Player|Game|Team $game): void {
		if (isset($game->tournamentPlayer)) {
			$data['tournamentPlayer'] = $game->tournamentPlayer->idPublic;
		}
	}

	public function save(Player|Game|Team $game): bool {
		if (isset($game->tournamentPlayer)) {
			return $game->tournamentPlayer->save();
		}
		return true;
	}
}