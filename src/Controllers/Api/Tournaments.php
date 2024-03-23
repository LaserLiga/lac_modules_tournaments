<?php

namespace LAC\Modules\Tournament\Controllers\Api;

use LAC\Modules\Tournament\Models\Tournament;
use LAC\Modules\Tournament\Services\TournamentProvider;
use Lsr\Core\Controllers\ApiController;
use Lsr\Core\Templating\Latte;
use Psr\Http\Message\ResponseInterface;

class Tournaments extends ApiController
{

	public function __construct(
		Latte                               $latte,
		private readonly TournamentProvider $tournamentProvider,
	) {
		parent::__construct($latte);
	}

	public function getAll(): ResponseInterface {
		return $this->respond(Tournament::getAll());
	}

	public function get(Tournament $tournament): ResponseInterface {
		return $this->respond($tournament);
	}

	public function sync(): ResponseInterface {
		if ($this->tournamentProvider->sync()) {
			return $this->respond(['status' => 'ok']);
		}
		return $this->respond(['status' => 'error'], 500);
	}

	public function recalculatePoints(Tournament $tournament): ResponseInterface {
		$this->tournamentProvider->recalcTeamPoints($tournament);

		$this->tournamentProvider->syncGames($tournament);

		return $this->respond(['status' => 'ok']);
	}

	public function syncGames(Tournament $tournament): ResponseInterface {
		if ($this->tournamentProvider->syncGames($tournament)) {
			return $this->respond(['status' => 'ok']);
		}
		return $this->respond(['status' => 'error'], 500);
	}

}