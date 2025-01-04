<?php

namespace LAC\Modules\Tournament\Models;

use App\GameModels\Factory\GameFactory;
use App\Models\BaseModel;
use DateTimeInterface;
use Lsr\Orm\Attributes\NoDB;
use Lsr\Orm\Attributes\PrimaryKey;
use Lsr\Orm\Attributes\Relations\ManyToMany;
use Lsr\Orm\Attributes\Relations\ManyToOne;
use Lsr\Orm\Attributes\Relations\OneToMany;
use Lsr\Orm\ModelCollection;

#[PrimaryKey('id_game')]
class Game extends BaseModel
{
    use WithPublicId;

    public const string TABLE = 'tournament_games';

    #[ManyToOne]
    public Tournament $tournament;

    #[ManyToOne]
    public ?Group $group;

    /** @var ModelCollection<Player> */
    #[ManyToMany('tournament_game_players', class: Player::class)]
    public ModelCollection $players;

    /** @var ModelCollection<GameTeam> */
    #[OneToMany(class: GameTeam::class)]
    public ModelCollection $teams;

    public ?string $code = null;
    public DateTimeInterface $start;

    #[NoDB]
    public ?\App\GameModels\Game\Game $game = null {
        get {
            if (!isset($this->code)) {
                return null;
            }
            $this->game = GameFactory::getByCode($this->code);
            return $this->game;
        }
    }
    #[NoDB]
    public ?Game $nextGame = null {
        get {
            if (!isset($this->nextGame)) {
                $this->nextGame = $this->tournament->queryGames()
                                                   ->where('[start] > %dt', $this->start)
                                                   ->orderBy('start')
                                                   ->first();
            }
            return $this->nextGame;
        }
    }
    #[NoDB]
    public ?Game $prevGame = null {
        get {
            if (!isset($this->prevGame)) {
                $this->prevGame = $this->tournament->queryGames()
                                                   ->where('[start] < %dt', $this->start)
                                                   ->orderBy('start')
                                                   ->desc()
                                                   ->first();
            }
            return $this->prevGame;
        }
    }

    public function hasScores() : bool {
        return $this->game !== null;
    }

    public function save() : bool {
        $success = parent::save();
        foreach ($this->teams as $team) {
            $team->save();
        }
        foreach ($this->players as $player) {
            $player->save();
        }
        return $success;
    }

    public function hasTeam(Team $team) : bool {
        foreach ($this->teams as $checkTeam) {
            if ($team->id === $checkTeam->team?->id) {
                return true;
            }
        }
        return false;
    }
}
