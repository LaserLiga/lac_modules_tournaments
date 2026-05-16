<?php

namespace LAC\Modules\Tournament\Gate\Screens;

use App\GameModels\Game\Lasermaxx\Evo5\Player as Evo5Player;
use App\GameModels\Game\Lasermaxx\Evo6\Player as Evo6Player;
use App\Gate\Screens\GateScreen;
use LAC\Modules\Tournament\Models\Game;
use LAC\Modules\Tournament\Models\Player as TournamentPlayer;
use LAC\Modules\Tournament\Models\Team;
use LAC\Modules\Tournament\Models\Tournament;
use Lsr\Db\DB;
use RuntimeException;

abstract class AbstractTournamentScreen extends GateScreen
{
    private Tournament $tournament;

    public static function getGroup(): string
    {
        return lang('Turnaj', domain: 'gate', context: 'screens.groups');
    }

    public function getTournament(): Tournament
    {
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

    public function setTournament(Tournament $tournament): static
    {
        $this->tournament = $tournament;
        return $this;
    }

    /**
     * @return Team[]
     */
    protected function getTeamsSorted(Tournament $tournament): array
    {
        $teams = $tournament->teams;
        usort(
            $teams,
            static function (Team $a, Team $b) {
                $diff = $b->points - $a->points;
                if ($diff !== 0) {
                    return $diff;
                }
                return $b->score - $a->score;
            }
        );

        return $teams;
    }

    /**
     * @return array<int,array{player:TournamentPlayer,value:int|float}>
     */
    protected function getTopPlayers(Tournament $tournament, string $metric, int $limit = 12): array
    {
        $playerIds = $this->getTournamentPlayerIds($tournament);
        if ($playerIds === []) {
            return [];
        }

        $values = match ($metric) {
            'skill' => $this->fetchAveragePlayerValues($playerIds, 'skill'),
            'accuracy' => $this->fetchMaxPlayerValues($playerIds, 'accuracy'),
            'shots' => $this->fetchSumPlayerValues($playerIds, 'shots'),
            'hitsOwn' => $this->fetchSumPlayerValues($playerIds, 'hits_own'),
            default => [],
        };

        arsort($values);

        $players = [];
        foreach (array_slice($values, 0, $limit, true) as $playerId => $value) {
            $players[] = [
                'player' => TournamentPlayer::get((int)$playerId),
                'value' => $value,
            ];
        }

        return $players;
    }

    /**
     * @return int[]
     */
    private function getTournamentPlayerIds(Tournament $tournament): array
    {
        $playerIds = [];
        foreach ($tournament->teams as $team) {
            foreach ($team->players as $player) {
                if (isset($player->id)) {
                    $playerIds[] = $player->id;
                }
            }
        }

        return $playerIds;
    }

    /**
     * @param int[] $playerIds
     * @return array<int,float>
     */
    private function fetchAveragePlayerValues(array $playerIds, string $column): array
    {
        $sums = [];
        $counts = [];
        foreach ([Evo5Player::TABLE, Evo6Player::TABLE] as $table) {
            $fields = '[id_tournament_player], SUM([' . $column . ']) as [value], COUNT(*) as [count]';
            $systemRows = DB::select($table, $fields)
                ->where('[id_tournament_player] IN %in', $playerIds)
                ->groupBy('id_tournament_player')
                ->fetchAll();

            foreach ($systemRows as $row) {
                $playerId = (int)$row->id_tournament_player;
                $sums[$playerId] = ($sums[$playerId] ?? 0.0) + (float)$row->value;
                $counts[$playerId] = ($counts[$playerId] ?? 0) + (int)$row->count;
            }
        }

        $values = [];
        foreach ($sums as $playerId => $sum) {
            $values[$playerId] = $sum / max(1, $counts[$playerId] ?? 1);
        }

        return $values;
    }

    /**
     * @param int[] $playerIds
     * @return array<int,int>
     */
    private function fetchMaxPlayerValues(array $playerIds, string $column): array
    {
        $values = [];
        foreach ([Evo5Player::TABLE, Evo6Player::TABLE] as $table) {
            $systemRows = DB::select($table, '[id_tournament_player], MAX([' . $column . ']) as [value]')
                ->where('[id_tournament_player] IN %in', $playerIds)
                ->groupBy('id_tournament_player')
                ->fetchAll();

            foreach ($systemRows as $row) {
                $playerId = (int)$row->id_tournament_player;
                $values[$playerId] = max($values[$playerId] ?? 0, (int)$row->value);
            }
        }

        return $values;
    }

    /**
     * @param int[] $playerIds
     * @return array<int,int>
     */
    private function fetchSumPlayerValues(array $playerIds, string $column): array
    {
        $values = [];
        foreach ([Evo5Player::TABLE, Evo6Player::TABLE] as $table) {
            $systemRows = DB::select($table, '[id_tournament_player], SUM([' . $column . ']) as [value]')
                ->where('[id_tournament_player] IN %in', $playerIds)
                ->groupBy('id_tournament_player')
                ->fetchAll();

            foreach ($systemRows as $row) {
                $playerId = (int)$row->id_tournament_player;
                $values[$playerId] = ($values[$playerId] ?? 0) + (int)$row->value;
            }
        }

        return $values;
    }
}
