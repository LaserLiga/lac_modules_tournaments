<?php

namespace LAC\Modules\Tournament\Models;

use App\GameModels\Factory\GameFactory;
use App\Models\BaseModel;
use DateTimeInterface;
use Lsr\ObjectValidation\Attributes\NoValidate;
use Lsr\Orm\Attributes\Hooks\AfterInsert;
use Lsr\Orm\Attributes\Hooks\AfterUpdate;
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

    #[NoDB, NoValidate]
    public ?\App\GameModels\Game\Game $game = null {
        get {
            if (!isset($this->code)) {
                return null;
            }
            $this->game = GameFactory::getByCode($this->code);
            return $this->game;
        }
    }
    #[NoDB, NoValidate]
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
    #[NoDB, NoValidate]
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

    #[AfterUpdate, AfterInsert]
    public function saveTeams() : bool {
        echo 'Saving teams for game '.$this->id.PHP_EOL;
        var_dump($this->teams->count());
        foreach ($this->teams as $team) {
            if (!$team->save()) {
                echo 'Failed to save team '.$team->name.' for game '.$this->id.PHP_EOL;
                return false;
            }
        }
        return true;
    }

    #[AfterUpdate, AfterInsert]
    public function savePlayers() : bool {
        echo 'Saving players for game '.$this->id.PHP_EOL;
        foreach ($this->players as $player) {
            if (!$player->save()) {
                echo 'Failed to save player '.$player->name.' for game '.$this->id.PHP_EOL;
                return false;
            }
        }
        return true;
    }

    public function hasScores() : bool {
        return $this->game !== null;
    }

    public function hasTeam(Team $team) : bool {
        foreach ($this->teams as $checkTeam) {
            if ($checkTeam instanceof GameTeam && $team->id === $checkTeam->team?->id) {
                return true;
            }
        }
        return false;
    }
}
