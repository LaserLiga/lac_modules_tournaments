<?php

namespace LAC\Modules\Tournament\Services;

use App\GameModels\Game\Game;
use JsonException;
use LAC\Modules\Core\LigaApiExtensionInterface;
use Lsr\ObjectValidation\Exceptions\ValidationException;

class LigaApiExtension implements LigaApiExtensionInterface
{
    private array $tournaments = [];

    public function __construct(
        private readonly TournamentProvider $tournamentProvider
    ) {
    }

    /**
     * @inheritDoc
     */
    public function beforeGameSync(string $system, array $games): void {
        $this->tournaments = [];
    }

    public function processGameBeforeSync(Game $game): void {
        if (isset($game->tournamentGame)) {
            $tournament = $game->tournamentGame->tournament;
            $this->tournaments[$tournament->id] = $tournament;
        }
    }

    /**
     * @inheritDoc
     */
    public function afterGameSync(string $system, array $games): void {
        if (!empty($this->tournaments)) {
            bdump($this->tournaments);
            foreach ($this->tournaments as $tournament) {
                try {
                    $this->tournamentProvider->syncGames($tournament);
                } catch (JsonException | ValidationException $e) {
                    bdump($e);
                }
            }
        }
    }
}
