<?php

namespace LAC\Modules\Tournament\Controllers;

use App\GameModels\Game\Evo5\Player;
use App\Gate\Gate;
use App\Gate\Models\GateType;
use LAC\Modules\Tournament\Models\Player as TournamentPlayer;
use LAC\Modules\Tournament\Models\Team;
use LAC\Modules\Tournament\Models\Tournament;
use Lsr\Core\Controllers\Controller;
use Lsr\Core\Requests\Dto\ErrorResponse;
use Lsr\Core\Requests\Enums\ErrorType;
use Lsr\Core\Requests\Request;
use Lsr\Db\DB;
use Lsr\ObjectValidation\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class TournamentResults extends Controller
{
    public function __construct(private readonly Gate $gate) {
    }

    public function results(Tournament $tournament): ResponseInterface {
        $this->params['tournament'] = $tournament;
        $this->params['teams'] = $tournament->teams;
        $this->params['games'] = $tournament->getGames();

        usort(
            $this->params['teams'],
            static function (Team $a, Team $b) {
                $diff = $b->points - $a->points;
                if ($diff !== 0) {
                    return $diff;
                }
                return $b->score - $a->score;
            }
        );

        $this->params['bestPlayers'] = [];
        $this->params['accuracyPlayers'] = [];
        $this->params['shotsPlayers'] = [];
        $this->params['hitsOwnPlayers'] = [];

        $playerIds = [];
        foreach ($this->params['teams'] as $team) {
            foreach ($team->players as $player) {
                $playerIds[] = $player->id;
            }
        }

        $bestPlayers = DB::select(Player::TABLE, '[id_tournament_player], [name], AVG([skill]) as [skill]')
            ->where('[id_tournament_player] IN %in', $playerIds)
            ->groupBy('id_tournament_player')
            ->orderBy('skill')
            ->desc()
            ->cacheTags(Player::TABLE, ...Player::CACHE_TAGS)
            ->fetchAll();
        foreach ($bestPlayers as $row) {
            $this->params['bestPlayers'][] = [
                'player' => TournamentPlayer::get($row->id_tournament_player),
                'value'  => $row->skill,
            ];
        }

        $accuracyPlayers = DB::select(Player::TABLE, '[id_tournament_player], [name], MAX([accuracy]) as [accuracy]')
            ->where('[id_tournament_player] IN %in', $playerIds)
            ->groupBy('id_tournament_player')
            ->orderBy('accuracy')
            ->desc()
            ->cacheTags(Player::TABLE, ...Player::CACHE_TAGS)
            ->fetchAll();
        foreach ($accuracyPlayers as $row) {
            $this->params['accuracyPlayers'][] = [
                'player' => TournamentPlayer::get($row->id_tournament_player),
                'value'  => $row->accuracy,
            ];
        }

        $shotsPlayers = DB::select(Player::TABLE, '[id_tournament_player], [name], SUM([shots]) as [shots]')
            ->where('[id_tournament_player] IN %in', $playerIds)
            ->groupBy('id_tournament_player')
            ->orderBy('shots')
            ->desc()
            ->cacheTags(Player::TABLE, ...Player::CACHE_TAGS)
            ->fetchAll();
        foreach ($shotsPlayers as $row) {
            $this->params['shotsPlayers'][] = [
                'player' => TournamentPlayer::get($row->id_tournament_player),
                'value'  => $row->shots,
            ];
        }

        $hitsOwnPlayers = DB::select(Player::TABLE, '[id_tournament_player], [name], SUM([hits_own]) as [hitsOwn]')
            ->where('[id_tournament_player] IN %in', $playerIds)
            ->groupBy('id_tournament_player')
            ->orderBy('hitsOwn')
            ->desc()
            ->cacheTags(Player::TABLE, ...Player::CACHE_TAGS)
            ->fetchAll();
        foreach ($hitsOwnPlayers as $row) {
            $this->params['hitsOwnPlayers'][] = [
                'player' => TournamentPlayer::get($row->id_tournament_player),
                'value'  => $row->hitsOwn,
            ];
        }

        return $this->view('../modules/Tournament/templates/results');
    }

    public function gate(
        Tournament $tournament,
        Request    $request,
        string     $gate = 'tournament_default'
    ): ResponseInterface {
        $this->params['tournament'] = $tournament;

        // Allow for filtering games just from one system
        $system = $request->getGet('system', 'all');
        $gateType = GateType::getBySlug(empty($gate) ? 'tournament_default' : $gate);
        if (!isset($gateType)) {
            return $this->respond(new ErrorResponse('Gate type not found.', ErrorType::NOT_FOUND, values: ['slug' => $gate]), 404);
        }

        try {
            $screen = $this->gate
              ->getCurrentScreen($gateType, $system)
              ->setParams($this->params);
            return $screen->run()
                          ->withAddedHeader('X-Screen', $screen::getDiKey())
                          ->withAddedHeader('X-Trigger', $screen->getTrigger()?->value ?? 'none');
        } catch (ValidationException | Throwable $e) {
            return $this->respond(new ErrorResponse('An error has occured', exception: $e), 500);
        }
    }
}
