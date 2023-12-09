<?php

namespace LAC\Modules\Tournament\Models;

use Lsr\Core\Models\Attributes\ManyToOne;
use Lsr\Core\Models\Attributes\PrimaryKey;
use Lsr\Core\Models\Model;

#[PrimaryKey('id_game_team')]
class GameTeam extends Model
{

	public const TABLE = 'tournament_game_teams';

	#[ManyToOne]
	public Game $game;

	public int $key = 0;
	public ?int $position = null;
	public ?int $score = null;
	public ?int $points = null;

	#[ManyToOne]
	public ?Team $team = null;

	private string $name;

	public function getName(): string {
		if (isset($this->name)) {
			return $this->name;
		}
		if (isset($this->team)) {
			$this->name = $this->team->name;
			return $this->name;
		}

		if (isset($this->game->group)) {
			$progressions = $this->game->group->getProgressionsTo();
			foreach ($progressions as $progression) {
				$keys = $progression->getKeys();
				foreach ($keys as $i => $key) {
					if ($this->key === $key) {
						if (isset($progression->from)) {
							$this->name = sprintf(
								lang('%d. tým ze skupiny: %s'),
								$i + ($progression->start ?? 0) + 1,
								$progression->from->name
							);
						}
						else {
							$this->name = lang('Postupující tým');
						}
						return $this->name;
					}
				}
			}
			$progressions = $this->game->group->getMultiProgressionsTo();
			foreach ($progressions as $progression) {
				$keys = $progression->getKeys();
				foreach ($keys as $i => $key) {
					if ($this->key === $key) {
						if (!empty($progression->from)) {
							$groupNames = array_map(
								static fn(Group $group) => $group->name,
								$progression->from,
							);
							if ($progression->totalLength > 0) {
								$this->name = sprintf(
									lang('Nejlepší %d týmy z %d. týmů ze skupin: %s'),
									$progression->totalLength,
									$i + ($progression->start ?? 0) + 1,
									implode(', ', $groupNames)
								);
							}
							else {
								$this->name = sprintf(
									lang('%d. tým ze skupin: %s'),
									$i + ($progression->start ?? 0) + 1,
									implode(', ', $groupNames),
								);
							}
						}
						else {
							$this->name = lang('Postupující tým');
						}
						return $this->name;
					}
				}
			}
		}

		$this->name = sprintf(lang('Tým %s'), $this->key);
		return $this->name;
	}

}