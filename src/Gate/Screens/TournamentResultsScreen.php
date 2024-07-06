<?php

namespace LAC\Modules\Tournament\Gate\Screens;

use App\Gate\Screens\GateScreen;
use LAC\Modules\Tournament\Models\Game;
use LAC\Modules\Tournament\Models\Team;
use LAC\Modules\Tournament\Models\Tournament;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class TournamentResultsScreen extends GateScreen
{
    private Tournament $tournament;

    /**
     * @inheritDoc
     */
    public static function getName(): string {
        return lang('Výsledky turnaje', domain: 'gate', context: 'screens');
    }

    /**
     * @inheritDoc
     */
    public static function getDiKey(): string {
        return 'gate.tournament.results';
    }

    /**
     * @inheritDoc
     */
    public function run(): ResponseInterface {
        $tournament = $this->getTournament();
        $teams = $tournament->getTeams();
        usort(
            $teams,
            static function (Team $a, Team $b) {
                $diff = $b->points - $a->points;
                if ($diff !== 0) {
                    return $diff;
                }
                return $b->getScore() - $a->getScore();
            }
        );

        return $this
            ->view(
                '../modules/Tournament/templates/gate',
                [
                    'tournament' => $tournament,
                    'games'      => $tournament->getGames(),
                    'teams'      => $teams,
                    'addJs'      => ['modules/tournament/gate.js'],
                    'addCss'     => ['modules/tournament/gate.css'],
                ]
            );
    }

    public function getTournament(): Tournament {
        if (!isset($this->tournament)) {
            if (isset($this->params['tournament'])) {
                $this->tournament = $this->params['tournament'];
            } else {
                $lastTournament = Game::query()->orderBy('start')->desc()->first()?->tournament;
                if (!isset($lastTournament)) {
                    throw new RuntimeException('Cannot find last active tournament');
                }
                $this->tournament = $lastTournament;
            }
        }
        return $this->tournament;
    }

    public function setTournament(Tournament $tournament): TournamentResultsScreen {
        $this->tournament = $tournament;
        return $this;
    }
}
