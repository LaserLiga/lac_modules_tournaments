<?php

namespace LAC\Modules\Tournament\Gate\Screens;

use Psr\Http\Message\ResponseInterface;

class TournamentPlayersScreen extends AbstractTournamentScreen
{
    public static function getName(): string
    {
        return lang('Nejlepší hráči turnaje', domain: 'gate', context: 'screens');
    }

    public static function getDiKey(): string
    {
        return 'gate.tournament.players';
    }

    public function run(): ResponseInterface
    {
        $tournament = $this->getTournament();

        return $this->view(
            '../modules/Tournament/templates/gate/players',
            [
                'tournament' => $tournament,
                'bestPlayers' => $this->getTopPlayers($tournament, 'skill', 8),
                'accuracyPlayers' => $this->getTopPlayers($tournament, 'accuracy', 8),
                'shotsPlayers' => $this->getTopPlayers($tournament, 'shots', 8),
                'hitsOwnPlayers' => $this->getTopPlayers($tournament, 'hitsOwn', 8),
                'addJs' => ['modules/tournament/gate.js'],
                'addCss' => ['modules/tournament/gate.css'],
            ]
        );
    }
}
