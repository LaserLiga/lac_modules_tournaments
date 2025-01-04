<?php

namespace LAC\Modules\Tournament\Models;

use App\Models\BaseModel;
use Lsr\Db\DB;
use Lsr\ObjectValidation\Exceptions\ValidationException;
use Lsr\Orm\Attributes\NoDB;
use Lsr\Orm\Attributes\PrimaryKey;
use Lsr\Orm\Attributes\Relations\ManyToOne;

#[PrimaryKey('id_group')]
class Group extends BaseModel
{
    use WithPublicId;

    public const string TABLE = 'tournament_groups';

    public ?string $round = null;
    public string $name;
    #[ManyToOne]
    public Tournament $tournament;


    /** @var Progression[] */
    #[NoDB]
    public array $progressionsFrom = [] {
        get {
            if (empty($this->progressionsFrom)) {
                $this->progressionsFrom = Progression::query()->where('id_group_from = %i', $this->id)->get();
            }
            return $this->progressionsFrom;
        }
    }
    /** @var MultiProgression[] */
    #[NoDB]
    public array $multiProgressionsFrom = [] {
        get {
            if (empty($this->multiProgressionsFrom)) {
                $this->multiProgressionsFrom = MultiProgression::query()
                                                               ->where(
                                                                 'id_progression IN %sql',
                                                                 DB::select(
                                                                   'tournament_multi_progressions_from',
                                                                   'id_progression'
                                                                 )
                                                                   ->where('id_group = %i', $this->id)
                                                                   ->fluent
                                                               )
                                                               ->get();
            }
            return $this->multiProgressionsFrom;
        }
    }

    /** @var Game[] */
    #[NoDB]
    public array $games = [] {
        get {
            if (empty($this->games)) {
                $this->games = Game::query()->where('[id_group] = %i', $this->id)->get();
            }
            return $this->games;
        }
    }
    /** @var Progression[] */
    #[NoDB]
    public array $progressionsTo = [] {
        get {
            if (empty($this->progressionsTo)) {
                $this->progressionsTo = Progression::query()->where('id_group_to = %i', $this->id)->get();
            }
            return $this->progressionsTo;
        }
    }
    /** @var MultiProgression[] */
    #[NoDB]
    public array $multiProgressionsTo = [] {
        get {
            if (empty($this->multiProgressionsTo)) {
                $this->multiProgressionsTo = MultiProgression::query()->where('id_group_to = %i', $this->id)->get();
            }
            return $this->multiProgressionsTo;
        }
    }

    /** @var Team[] */
    #[NoDB]
    public array $teams {
        get {
            if (!isset($this->teams)) {
                $this->teams = Team::query()
                                   ->where(
                                     'id_team IN %sql',
                                     DB::select(GameTeam::TABLE, 'id_team')
                                       ->where(
                                         'id_game IN %sql',
                                         DB::select(Game::TABLE, 'id_game')
                                           ->where('id_group = %i', $this->id)
                                           ->fluent
                                       )
                                       ->fluent
                                   )
                                   ->cacheTags(
                                     'tournament/group/teams',
                                     'tournament/'.$this->tournament->id.'/group/teams',
                                     'tournament/'.$this->tournament->id.'/group/'.$this->id.'/teams'
                                   )
                                   ->get();
            }
            return $this->teams;
        }
    }

    /**
     * @return Team[]
     * @throws ValidationException
     */
    public function getTeamsSorted() : array {
        $teams = $this->teams;
        usort(
          $teams,
          function (Team $a, Team $b) {
              $pointsA = $a->getPointsForGroup($this);
              $pointsB = $b->getPointsForGroup($this);
              if ($pointsA === $pointsB) {
                  return $b->getScoreForGroup($this) - $a->getScoreForGroup($this);
              }
              return $pointsB - $pointsA;
          }
        );
        return $teams;
    }
}
