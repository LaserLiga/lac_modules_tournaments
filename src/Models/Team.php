<?php

namespace LAC\Modules\Tournament\Models;

use App\Models\BaseModel;
use Lsr\Db\DB;
use Lsr\ObjectValidation\Exceptions\ValidationException;
use Lsr\Orm\Attributes\NoDB;
use Lsr\Orm\Attributes\PrimaryKey;
use Lsr\Orm\Attributes\Relations\ManyToOne;
use Lsr\Orm\ModelTraits\WithCreatedAt;
use Lsr\Orm\ModelTraits\WithUpdatedAt;

#[PrimaryKey('id_team')]
class Team extends BaseModel
{
    use WithPublicId;
    use WithUpdatedAt;
    use WithCreatedAt;

    public const string TABLE = 'tournament_teams';

    public string $name;

    public ?string $image = null;

    public int $points = 0;

    #[ManyToOne]
    public Tournament $tournament;

    /** @var Player[] */
    #[NoDB]
    public array $players = [] {
        get {
            if (empty($this->players)) {
                $this->players = Player::query()->where('id_team = %i', $this->id)->get();
            }
            return $this->players;
        }
    }

    #[NoDB]
    public int $score {
        get {
            if (!isset($this->score)) {
                $this->score = DB::select(GameTeam::TABLE, 'SUM([score])')
                                 ->where('[id_team] = %i', $this->id)
                                 ->fetchSingle(
                                   false
                                 ) ?? 0;
            }
            return $this->score;
        }
    }
    #[NoDB]
    public int $wins {
        get {
            if (!isset($this->wins)) {
                $this->wins = DB::select(GameTeam::TABLE, 'COUNT(*)')->where(
                  '[id_team] = %i AND [points] = %i',
                  $this->id,
                  $this->tournament->points->win
                )->fetchSingle(false) ?? 0;
            }
            return $this->wins;
        }
    }
    #[NoDB]
    public int $draws {
        get {
            if (!isset($this->draws)) {
                $this->draws = DB::select(GameTeam::TABLE, 'COUNT(*)')->where(
                  '[id_team] = %i AND [points] = %i',
                  $this->id,
                  $this->tournament->points->draw
                )->fetchSingle(false) ?? 0;
            }
            return $this->draws;
        }
    }

    #[NoDB]
    public int $losses {
        get {
            if (!isset($this->losses)) {
                $this->losses = DB::select(GameTeam::TABLE, 'COUNT(*)')->where(
                  '[id_team] = %i AND [points] = %i',
                  $this->id,
                  $this->tournament->points->loss
                )->fetchSingle(false) ?? 0;
            }
            return $this->losses;
        }
    }
    /**
     * @var array<int,int>
     */
    #[NoDB]
    public array $groupKeys {
        get {
            $this->groupKeys ??= DB::select([GameTeam::TABLE, 'a'], 'b.id_group, a.key')->join(Game::TABLE, 'b')->on(
              'a.id_game = b.id_game'
            )->where('a.id_team = %i', $this->id)->groupBy('id_group')->fetchPairs('id_group', 'key', false);
            return $this->groupKeys;
        }
    }

    /** @var Game[] */
    #[NoDB]
    public array $games = [] {
        get {
            if (empty($this->games)) {
                foreach ($this->tournament->getGames() as $game) {
                    if ($game->hasTeam($this)) {
                        $this->games[] = $game;
                    }
                }
            }
            return $this->games;
        }
    }

    public function getScoreForGroup(Group $group) : int {
        $gameIds = array_map(static fn(Game $game) => $game->id, $group->games);
        return DB::select(GameTeam::TABLE, 'SUM([score])')
                 ->where('[id_team] = %i AND [id_game] IN %in', $this->id, $gameIds)
                 ->fetchSingle(false) ?? 0;
    }

    public function getPointsForGroup(Group $group) : int {
        $gameIds = array_map(static fn(Game $game) => $game->id, $group->games);
        return DB::select(GameTeam::TABLE, 'SUM([points])')
                 ->where('[id_team] = %i AND [id_game] IN %in', $this->id, $gameIds)
                 ->fetchSingle(false) ?? 0;
    }

    /**
     * @return string|null
     */
    public function getImageUrl() : ?string {
        if (empty($this->image)) {
            return null;
        }
        return $this->image;
    }

    /**
     * @return Game[]
     * @throws ValidationException
     */
    public function getGamesAgainst(Team $team) : array {
        $games = [];
        foreach ($this->games as $game) {
            if ($game->hasTeam($team)) {
                $games[] = $game;
            }
        }
        return $games;
    }
}
