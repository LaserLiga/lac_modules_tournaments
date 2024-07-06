<?php

namespace LAC\Modules\Tournament\Models;

use Lsr\Core\DB;
use Lsr\Core\Exceptions\ValidationException;
use Lsr\Core\Models\Attributes\ManyToOne;
use Lsr\Core\Models\Attributes\PrimaryKey;
use Lsr\Core\Models\Model;

#[PrimaryKey('id_group')]
class Group extends Model
{
    use WithPublicId;

    public const TABLE = 'tournament_groups';

    public ?string $round = null;
    public string $name;
    #[ManyToOne]
    public Tournament $tournament;


    /** @var Progression[] */
    private array $progressionsFrom = [];
    /** @var MultiProgression[] */
    private array $multiProgressionsFrom = [];

    /** @var Game[] */
    private array $games = [];
    /** @var Progression[] */
    private array $progressionsTo = [];
    /** @var MultiProgression[] */
    private array $multiProgressionsTo = [];

    /** @var Team[] */
    private array $teams;

    /**
     * @return Progression[]
     * @throws ValidationException
     */
    public function getProgressionsFrom(): array {
        if (empty($this->progressionsFrom)) {
            $this->progressionsFrom = Progression::query()->where('id_group_from = %i', $this->id)->get();
        }
        return $this->progressionsFrom;
    }

    /**
     * @return MultiProgression[]
     * @throws ValidationException
     */
    public function getMultiProgressionsFrom(): array {
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

    /**
     * @return Progression[]
     * @throws ValidationException
     */
    public function getProgressionsTo(): array {
        if (empty($this->progressionsTo)) {
            $this->progressionsTo = Progression::query()->where('id_group_to = %i', $this->id)->get();
        }
        return $this->progressionsTo;
    }

    /**
     * @return MultiProgression[]
     * @throws ValidationException
     */
    public function getMultiProgressionsTo(): array {
        if (empty($this->multiProgressionsTo)) {
            $this->multiProgressionsTo = MultiProgression::query()->where('id_group_to = %i', $this->id)->get();
        }
        return $this->multiProgressionsTo;
    }

    /**
     * @return Game[]
     * @throws ValidationException
     */
    public function getGames(): array {
        if (empty($this->games)) {
            $this->games = Game::query()->where('[id_group] = %i', $this->id)->get();
        }
        return $this->games;
    }

    /**
     * @return Team[]
     * @throws ValidationException
     */
    public function getTeams(): array {
        $this->teams ??= Team::query()
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
                                 'tournament/' . $this->tournament->id . '/group/teams',
                                 'tournament/' . $this->tournament->id . '/group/' . $this->id . '/teams'
                             )
                             ->get();
        return $this->teams;
    }

    /**
     * @return Team[]
     * @throws ValidationException
     */
    public function getTeamsSorted(): array {
        $teams = $this->getTeams();
        usort($teams, function (Team $a, Team $b) {
            $pointsA = $a->getPointsForGroup($this);
            $pointsB = $b->getPointsForGroup($this);
            if ($pointsA === $pointsB) {
                return $b->getScoreForGroup($this) - $a->getScoreForGroup($this);
            }
            return $pointsB - $pointsA;
        });
        return $teams;
    }
}
