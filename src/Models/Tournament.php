<?php

namespace LAC\Modules\Tournament\Models;

use App\Core\App;
use App\GameModels\Game\Enums\GameModeType;
use App\Models\BaseModel;
use App\Models\GameGroup;
use DateTimeInterface;
use Lsr\ObjectValidation\Exceptions\ValidationException;
use Lsr\Orm\Attributes\Instantiate;
use Lsr\Orm\Attributes\NoDB;
use Lsr\Orm\Attributes\PrimaryKey;
use Lsr\Orm\Attributes\Relations\ManyToOne;
use Lsr\Orm\Attributes\Relations\OneToMany;
use Lsr\Orm\ModelCollection;
use Lsr\Orm\ModelQuery;

#[PrimaryKey('id_tournament')]
class Tournament extends BaseModel
{
    use WithPublicId;

    public const string TABLE = 'tournaments';

    #[ManyToOne]
    public ?GameGroup $group = null;

    #[ManyToOne]
    public ?League $league = null;

    /** @var ModelCollection<Group> */
    #[OneToMany(class: Group::class)]
    public ModelCollection $groups;

    public string $name;
    public ?string $description = null;

    public ?string $image = null;
    public GameModeType $format = GameModeType::TEAM;

    public int $teamsInGame = 2;
    public int $teamSize = 1;
    public int $subCount = 0;

    #[Instantiate]
    public TournamentPoints $points;

    public int $gameLength = 15;
    public int $gamePause = 5;

    public bool $active = true;

    public DateTimeInterface $start;
    public ?DateTimeInterface $end = null;

    /** @var Team[] */
    #[NoDB]
    public array $teams = [] {
        get {
            if ($this->format === GameModeType::SOLO) {
                return [];
            }
            if (empty($this->teams)) {
                $this->teams = Team::query()->where('id_tournament = %i', $this->id)
                                   ->cacheTags(
                                     'tournament-teams',
                                     'tournament-'.$this->id.'-teams'
                                   )
                                   ->get();
            }
            return $this->teams;
        }
    }
    /** @var Player[] */
    #[NoDB]
    public array $players = [] {
        get {
            if ($this->format === GameModeType::TEAM) {
                return [];
            }
            if (empty($this->players)) {
                $this->players = Player::query()
                                       ->where('id_tournament = %i', $this->id)
                                       ->cacheTags(
                                         'tournament-players',
                                         'tournament-'.$this->id.'-players'
                                       )
                                       ->get();
            }
            return $this->players;
        }
    }
    /** @var Game[] */
    private array $games = [];
    /** @var Progression[] */
    private array $progressions = [];

    /** @var MultiProgression[] */
    private array $multiProgressions = [];

    public function getImageUrl() : ?string {
        if (!isset($this->image)) {
            return null;
        }
        return App::getInstance()->getBaseUrl().$this->image;
    }

    public function clearGames() : void {
        foreach ($this->getGames() as $game) {
            $game->delete();
        }
        $this->games = [];
    }

    /**
     * @return Game[]
     * @throws ValidationException
     */
    public function getGames() : array {
        if (empty($this->games)) {
            $this->games = $this->queryGames()->get();
        }
        return $this->games;
    }

    /**
     * @return ModelQuery<Game>
     */
    public function queryGames() : ModelQuery {
        return Game::query()->where('id_tournament = %i', $this->id);
    }

    public function clearGroups() : void {
        foreach ($this->groups as $group) {
            $group->delete();
        }
        $this->groups = new ModelCollection();
    }

    public function clearProgressions() : void {
        foreach ($this->getProgressions() as $progression) {
            $progression->delete();
        }
        foreach ($this->getMultiProgressions() as $progression) {
            $progression->delete();
        }
        $this->progressions = [];
        $this->multiProgressions = [];
    }

    /**
     * @return Progression[]
     * @throws ValidationException
     */
    public function getProgressions() : array {
        if (empty($this->progressions)) {
            $this->progressions = Progression::query()->where('id_tournament = %i', $this->id)->get();
        }
        return $this->progressions;
    }

    public function getMultiProgressions() : array {
        if (empty($this->multiProgressions)) {
            $this->multiProgressions = MultiProgression::query()->where('id_tournament = %i', $this->id)->get();
        }
        return $this->multiProgressions;
    }

    public function getPlannedGame() : ?Game {
        $game = $this->queryGames()->where('[code] IS NULL')->orderBy('start')->first();
        return $game ?? $this->queryGames()->orderBy('start')->first();
    }

    /**
     * @return GameGroup
     * @throws ValidationException
     */
    public function getGroup() : GameGroup {
        if (!isset($this->group)) {
            $this->group = new GameGroup();
            $this->group->name = $this->name;
            $this->group->active = false;
            $this->group->save();
            $this->save();
        }
        return $this->group;
    }
}
