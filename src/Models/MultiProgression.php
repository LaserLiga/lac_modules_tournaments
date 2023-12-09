<?php

namespace LAC\Modules\Tournament\Models;

use JsonException;
use Lsr\Core\DB;
use Lsr\Core\Models\Attributes\ManyToMany;
use Lsr\Core\Models\Attributes\ManyToOne;
use Lsr\Core\Models\Attributes\NoDB;
use Lsr\Core\Models\Attributes\PrimaryKey;
use Lsr\Core\Models\Model;

#[PrimaryKey('id_progression')]
class MultiProgression extends Model
{

	use WithPublicId;

	public const TABLE = 'tournament_multi_progressions';

	#[ManyToOne]
	public Tournament $tournament;
	/** @var array|null */
	#[ManyToMany(through: 'tournament_multi_progressions_from', class: Group::class)]
	public array $from = [];
	#[ManyToOne('id_group', 'id_group_to')]
	public Group $to;

	public ?int    $start       = null;
	public ?int    $length      = null;
	public ?int    $totalStart  = null;
	public ?int    $totalLength = null;
	public ?string $filters     = null;
	public ?string $keys        = null;
	public int     $points      = 0;

	#[NoDB]
	public ?\TournamentGenerator\MultiProgression $progression = null;

	/** @var int[] */
	private array $keysParsed = [];

	/**
	 * @return int[]
	 */
	public function getKeys(): array {
		if (empty($this->keysParsed) && !empty($this->keys)) {
			try {
				$this->keysParsed = json_decode($this->keys, false, 512, JSON_THROW_ON_ERROR);
			} catch (JsonException) {
			}
		}
		return $this->keysParsed;
	}

	/**
	 * @param int[] $keys
	 *
	 * @throws JsonException
	 */
	public function setKeys(array $keys): MultiProgression {
		$this->keysParsed = $keys;
		$this->keys = json_encode($keys, JSON_THROW_ON_ERROR);
		return $this;
	}

	public function insert(): bool {
		return parent::insert() && $this->insertFrom();
	}

	private function insertFrom(): bool {
		$data = [];
		foreach ($this->from as $group) {
			$data[] = ['id_group' => $group->id, 'id_progression' => $this->id];
		}
		return DB::insert('tournament_multi_progressions_from', ...$data) === count($this->from);
	}

	public function update(): bool {
		$success = parent::update();
		if ($success) {
			DB::delete('tournament_multi_progressions_from', ['id_progression' => $this->id]);
			return $this->insertFrom();
		}
		return false;
	}

}