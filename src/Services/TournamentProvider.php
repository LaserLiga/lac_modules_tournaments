<?php

namespace LAC\Modules\Tournament\Services;

use App\GameModels\Factory\GameFactory;
use App\GameModels\Game\Game as AppGame;
use App\GameModels\Game\Player as AppGamePlayer;
use App\GameModels\Game\Team as AppGameTeam;
use App\Services\LaserLiga\LigaApi;
use App\Services\LaserLiga\PlayerProvider;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use LAC\Modules\Tournament\Dto\ApiLeague;
use LAC\Modules\Tournament\Dto\ApiTeam;
use LAC\Modules\Tournament\Dto\ApiTournament;
use LAC\Modules\Tournament\Models\Game;
use LAC\Modules\Tournament\Models\GameTeam;
use LAC\Modules\Tournament\Models\Group;
use LAC\Modules\Tournament\Models\League;
use LAC\Modules\Tournament\Models\MultiProgression;
use LAC\Modules\Tournament\Models\Player;
use LAC\Modules\Tournament\Models\Progression;
use LAC\Modules\Tournament\Models\Team;
use LAC\Modules\Tournament\Models\Tournament;
use LAC\Modules\Tournament\Models\TournamentPresetType;
use Lsr\Caching\Cache;
use Lsr\Db\DB;
use Lsr\Logging\Logger;
use Lsr\ObjectValidation\Exceptions\ValidationException;
use Lsr\Orm\Exceptions\ModelNotFoundException;
use RuntimeException;
use Symfony\Component\Serializer\Serializer;
use Throwable;
use TournamentGenerator\BlankTeam;
use TournamentGenerator\Group as GeneratorGroup;
use TournamentGenerator\Interfaces\ProgressionInterface;
use TournamentGenerator\Team as GeneratorTeam;
use TournamentGenerator\Tournament as TournamentGenerator;

class TournamentProvider
{
    private Logger $logger;

    public function __construct(
        private readonly LigaApi        $api,
        private readonly PlayerProvider $playerProvider,
        private readonly Cache $cache,
        private readonly Serializer $serializer,
    ) {
        $this->logger = new Logger(LOG_DIR . 'services/', 'tournaments');
    }

    /**
     * @return bool
     */
    public function sync(): bool {
        // Sync leagues
        try {
            $response = $this->api->get('/api/leagues');
            /** @var ApiLeague[] $leagues */
            $leagues = $this->serializer->deserialize($response->getBody(), ApiLeague::class . '[]', 'json');
            $this->logger->debug('Got ' . count($leagues) . ' leagues');
            bdump($leagues);
            foreach ($leagues as $league) {
                $leagueLocal = League::getByPublicId($league->id);
                if (!isset($leagueLocal)) {
                    $leagueLocal = new League();
                }
                $leagueLocal->idPublic = $league->id;
                $leagueLocal->name = $league->name;
                $leagueLocal->description = $league->description;
                $leagueLocal->image = $league->image;
                $leagueLocal->save();
                $this->logger->debug(
                    'Saving league',
                    [
                        'id' => $leagueLocal->id,
                        'publicId' => $leagueLocal->idPublic,
                        'name' => $leagueLocal->name,
                        'description' => $leagueLocal->description,
                        'image' => $leagueLocal->image,
                    ]
                );
            }

            // Sync tournaments
            $response = $this->api->get('/api/tournament');
            if ($response->getStatusCode() !== 200) {
                $this->logger->error(
                    'Failed to fetch tournaments from API',
                    [
                        'status' => $response->getStatusCode(),
                        'response' => $response->getBody()->getContents(),
                    ]
                );
                return false;
            }
            /** @var ApiTournament[] $tournaments */
            $tournaments = $this->serializer->deserialize($response->getBody(), ApiTournament::class . '[]', 'json');
            foreach ($tournaments as $tournament) {
                $tournamentLocal = Tournament::getByPublicId($tournament->id);
                if (!isset($tournamentLocal)) {
                    $tournamentLocal = new Tournament();
                }
                $tournamentLocal->idPublic = $tournament->id;
                $tournamentLocal->name = $tournament->name;
                $tournamentLocal->description = $tournament->description;
                $tournamentLocal->image = $tournament->image;
                $tournamentLocal->format = $tournament->format;
                $tournamentLocal->teamSize = $tournament->teamSize;
                $tournamentLocal->teamsInGame = $tournament->teamsInGame;
                $tournamentLocal->subCount = $tournament->subCount;
                $tournamentLocal->active = $tournament->active;
                $tournamentLocal->points = $tournament->points;
                $tournamentLocal->start = $tournament->start;
                $tournamentLocal->end = $tournament->end;
                if (isset($tournament->league)) {
                    $tournamentLocal->league = League::getByPublicId($tournament->league->id);
                }
                $tournamentLocal->save();

                $response = $this->api->get(
                    '/api/tournament/' . $tournamentLocal->idPublic . '/teams',
                    ['withPlayers' => '1']
                );
                if ($response->getStatusCode() !== 200) {
                    $this->logger->error(
                        'Failed to fetch tournament team from API',
                        [
                            'tournamentId' => $tournamentLocal->idPublic,
                            'status' => $response->getStatusCode(),
                            'response' => $response->getBody()->getContents(),
                        ]
                    );
                    continue;
                }
                /** @var ApiTeam[] $teams */
                $teams = $this->serializer->deserialize($response->getBody(), ApiTeam::class . '[]', 'json');
                foreach ($teams as $team) {
                    $teamLocal = Team::getByPublicId($team->id);
                    if (!isset($teamLocal)) {
                        $teamLocal = new Team();
                    }
                    $teamLocal->idPublic = $team->id;
                    $teamLocal->tournament = $tournamentLocal;
                    $teamLocal->name = $team->name;
                    $teamLocal->image = $team->image;

                    $teamLocal->save();

                    foreach ($team->players as $player) {
                        if (!isset($player->id)) {
                            $this->logger->error(
                                'Invalid Player object - ' . $this->serializer->serialize($player, 'json')
                            );
                            continue;
                        }
                        $playerLocal = Player::getByPublicId($player->id);
                        if (!isset($playerLocal)) {
                            $playerLocal = new Player();
                        }
                        $playerLocal->idPublic = $player->id;
                        $playerLocal->tournament = $tournamentLocal;
                        $playerLocal->team = $teamLocal;
                        $playerLocal->nickname = $player->nickname;
                        $playerLocal->name = $player->name;
                        $playerLocal->surname = $player->surname;
                        $playerLocal->email = $player->email;
                        $playerLocal->phone = $player->phone;
                        $playerLocal->birthYear = $player->birthYear;
                        $playerLocal->image = $player->image;
                        $playerLocal->skill = $player->skill;
                        $playerLocal->captain = $player->captain;
                        $playerLocal->sub = $player->sub;

                        if (isset($player->user)) {
                            $this->logger->debug(
                                'Player #' . $player->id . ' user - ' .
                                $this->serializer->serialize($player->user, 'json')
                            );
                            $playerLocal->user = $this->playerProvider->getPlayerObjectFromData($player->user);
                        }

                        $playerLocal->save();
                    }
                }
            }
        } catch (Exception | GuzzleException $e) {
            // @phpstan-ignore-next-line
            $this->logger->exception($e);
            return false;
        }

        return true;
    }

    /**
     * @return bool
     * @throws JsonException
     * @throws ValidationException
     */
    public function syncUpcomingGames(): bool {
        $tournamentsWithGames = Tournament::query()->where(
            'DATE([start]) >= CURDATE() AND [id_tournament] IN %sql',
            DB::select(Game::TABLE, '[id_tournament]')->fluent
        )->get();

        $success = true;
        foreach ($tournamentsWithGames as $tournament) {
            $success = $success && $this->syncGames($tournament);
        }
        return $success;
    }

    /**
     * Synchronize tournament games to public
     *
     * @param Tournament $tournament
     *
     * @return bool
     * @throws JsonException
     * @throws ValidationException
     */
    public function syncGames(Tournament $tournament): bool {
        $this->logger->info('Starting game synchronization - #' . $tournament->id);
        if (!isset($tournament->idPublic)) {
            $this->logger->debug('No public ID - #' . $tournament->id);
            // TODO: Sync tournament to liga
            return false;
        }
        $data = [
            'group'  => null,
            'groups' => [],
            'games'  => [],
            'teams'  => [],
            'progressions' => [],
        ];
        $group = $tournament->getGroup();
        if (isset($group)) {
            $data['group'] = [
                'id' => $group->id,
                'name' => $group->name,
            ];
        }

        foreach ($tournament->groups as $group) {
            $data['groups'][] = [
                'id_local' => $group->id,
                'id_public' => $group->idPublic,
                'name'     => $group->name,
            ];
        }
        foreach ($tournament->teams as $team) {
            $data['teams'][] = [
                'id_local' => $team->id,
                'id_public' => $team->idPublic,
                'points'   => $team->points,
            ];
        }
        foreach ($tournament->getGames() as $game) {
            $teams = [];
            foreach ($game->teams as $team) {
                $teams[] = [
                    'key'    => $team->key,
                    'team'   => $team->team?->idPublic,
                    'position' => $team->position,
                    'points' => $team->points,
                    'score'  => $team->score,
                ];
            }
            $data['games'][] = [
                'id_local' => $game->id,
                'id_public' => $game->idPublic,
                'group'    => $game->group?->id,
                'code'     => $game->code,
                'start'    => $game->start->format('Y-m-d H:i:s'),
                'teams'    => $teams,
            ];
        }

        foreach ($tournament->getProgressions() as $progression) {
            $data['progressions'][] = [
                'id_local' => $progression->id,
                'id_public' => $progression->idPublic,
                'from'     => $progression->from?->id,
                'to'       => $progression->to->id,
                'start'    => $progression->start,
                'length'   => $progression->length,
                'filters'  => $progression->filters,
                'keys'     => $progression->keys,
                'points'   => $progression->points,
            ];
        }

        try {
            $response = $this->api->post('/api/tournament/' . $tournament->idPublic, $data);
            $response->getBody()->rewind();
        } catch (GuzzleException $e) {
            $this->logger->exception($e);
            return false;
        }
        if ($response->getStatusCode() !== 200) {
            $this->logger->warning($response->getBody()->getContents());
            return false;
        }

        $responseData = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        // Assign new public IDs
        foreach ($tournament->groups as $group) {
            if (isset($responseData['groups'][$group->id])) {
                $group->idPublic = $responseData['groups'][$group->id];
                $group->save();
            }
        }
        foreach ($tournament->getGames() as $game) {
            if (isset($responseData['games'][$game->id])) {
                $game->idPublic = $responseData['games'][$game->id];
                $game->save();
            }
        }
        foreach ($tournament->getProgressions() as $progression) {
            if (isset($responseData['progressions'][$progression->id])) {
                $progression->idPublic = $responseData['progressions'][$progression->id];
                $progression->save();
            }
        }

        return true;
    }

    public function reset(Tournament $tournament): void {
        $tournament->clearProgressions();
        $tournament->clearGroups();
        $tournament->clearGames();
    }

    /**
     * @return AppGame[]
     */
    public function getUnpairedResultGames(Tournament $tournament, int $limit = 30): array
    {
        $pairedCodes = array_filter(array_map(static fn(Game $game) => $game->code, $tournament->getGames()));
        $games = [];

        try {
            $rows = GameFactory::queryGames(true, $tournament->start)
                ->orderBy('end')
                ->desc()
                ->limit($limit * 3)
                ->fetchAll(cache: false);
        } catch (Throwable $e) {
            $this->logger->exception($e);
            return [];
        }

        foreach ($rows as $row) {
            if (in_array($row->code, $pairedCodes, true)) {
                continue;
            }
            try {
                $game = GameFactory::getById((int)$row->id_game, ['system' => (string)$row->system]);
            } catch (Throwable $e) {
                $this->logger->exception($e);
                continue;
            }
            if (!isset($game) || isset($game->tournamentGame)) {
                continue;
            }
            $games[] = $game;
            if (count($games) >= $limit) {
                break;
            }
        }

        return $games;
    }

    public function getResultGameByCode(string $code): ?AppGame
    {
        try {
            return GameFactory::getByCode($code);
        } catch (Throwable $e) {
            $this->logger->exception($e);
            return null;
        }
    }

    /**
     * @param array<int|string,int|string> $teamMap Source game team id => tournament game team id
     * @param array<int|string,int|string> $scores Source game team id => repaired score
     * @param array<int|string,int|string> $positions Source game team id => repaired position
     * @param array<int|string,int|string> $playerMap Source game player id => tournament player id
     *
     * @return array{teams:int,players:int,game:int}
     */
    public function pairImportedGame(
        Tournament $tournament,
        string     $code,
        int        $targetGameId,
        array      $teamMap,
        array      $scores,
        array      $positions,
        array      $playerMap,
        bool       $overwrite = false
    ): array
    {
        $sourceGame = $this->getResultGameByCode($code);
        if (!isset($sourceGame)) {
            throw new RuntimeException(lang('Importovaná hra nebyla nalezena', domain: 'tournament'));
        }

        $targetGame = Game::get($targetGameId);
        if ($targetGame->tournament->id !== $tournament->id) {
            throw new RuntimeException(lang('Hra nepatří do tohoto turnaje', domain: 'tournament'));
        }
        if ($targetGame->hasScores() && !$overwrite) {
            throw new RuntimeException(lang('Cílová turnajová hra už má výsledky', domain: 'tournament'));
        }

        $targetSlots = [];
        foreach ($targetGame->teams as $slot) {
            $targetSlots[$slot->id] = $slot;
        }

        $updatedTeams = 0;
        /** @var AppGameTeam $sourceTeam */
        foreach ($sourceGame->teams as $sourceTeam) {
            $sourceTeamId = $sourceTeam->id ?? null;
            if (!isset($sourceTeamId, $teamMap[$sourceTeamId]) || !is_numeric($teamMap[$sourceTeamId])) {
                continue;
            }
            $slotId = (int)$teamMap[$sourceTeamId];
            if (!isset($targetSlots[$slotId])) {
                throw new RuntimeException(lang('Neplatné přiřazení týmu', domain: 'tournament'));
            }
            $slot = $targetSlots[$slotId];
            if (!isset($slot->team)) {
                throw new RuntimeException(lang('Cílový slot nemá turnajový tým', domain: 'tournament'));
            }

            $sourceTeam->tournamentTeam = $slot->team;
            $sourceTeam->score = is_numeric($scores[$sourceTeamId] ?? null)
                ? (int)$scores[$sourceTeamId]
                : $sourceTeam->score;
            $sourceTeam->position = is_numeric($positions[$sourceTeamId] ?? null)
                ? (int)$positions[$sourceTeamId]
                : $sourceTeam->position;
            $sourceTeam->save();

            $slot->score = $sourceTeam->score;
            $slot->position = $sourceTeam->position;
            $slot->points = $this->getTournamentPointsForPosition($slot->position, $tournament);
            $slot->save();
            $updatedTeams++;
        }

        $updatedPlayers = 0;
        /** @var AppGamePlayer $sourcePlayer */
        foreach ($sourceGame->players as $sourcePlayer) {
            $sourcePlayerId = $sourcePlayer->id ?? null;
            if (!isset($sourcePlayerId, $playerMap[$sourcePlayerId]) || !is_numeric($playerMap[$sourcePlayerId])) {
                continue;
            }
            try {
                $sourcePlayer->tournamentPlayer = Player::get((int)$playerMap[$sourcePlayerId]);
            } catch (ModelNotFoundException) {
                continue;
            }
            if ($sourcePlayer->tournamentPlayer->tournament->id !== $tournament->id) {
                continue;
            }
            $sourcePlayer->save();
            $updatedPlayers++;
        }

        $sourceGame->tournamentGame = $targetGame;
        $targetGame->code = $sourceGame->code;
        $targetGame->save();
        $sourceGame->save();
        $this->recalcTeamPoints($tournament);
        $this->cleanTournamentCache($tournament);

        $this->logger->info(
            'Tournament imported game paired manually',
            [
                'tournament' => $tournament->id,
                'sourceGame' => $sourceGame->code,
                'targetGame' => $targetGame->id,
                'teams' => $updatedTeams,
                'players' => $updatedPlayers,
            ]
        );

        return ['teams' => $updatedTeams, 'players' => $updatedPlayers, 'game' => $targetGame->id];
    }

    /**
     * @return array{points:bool,progressed:int,synced:?bool}
     */
    public function recoverTournament(Tournament $tournament, bool $syncGames = false): array
    {
        $this->recalcTeamPoints($tournament);
        $progressed = $this->progress($tournament);
        $this->cleanTournamentCache($tournament);

        $synced = null;
        if ($syncGames) {
            $synced = $this->syncGames($tournament);
        }

        return [
            'points' => true,
            'progressed' => $progressed,
            'synced' => $synced,
        ];
    }

    public function buildMermaidBracket(Tournament $tournament): string
    {
        if (count($tournament->groups) === 0) {
            return '';
        }

        $lines = [
            'flowchart LR',
        ];
        $groups = [];
        foreach ($tournament->groups as $group) {
            $groups[$group->id] = $group;
            $lines[] = sprintf(
                '  G%d["%s"]',
                $group->id,
                $this->escapeMermaidLabel($this->getMermaidGroupLabel($group))
            );
        }
        foreach ($groups as $group) {
            $lines[] = sprintf('  class G%d %s', $group->id, $this->getMermaidGroupStatus($group));
        }

        foreach ($tournament->getProgressions() as $progression) {
            if (!isset($progression->from, $groups[$progression->from->id], $groups[$progression->to->id])) {
                continue;
            }

            $lines[] = sprintf(
                '  G%d -->|"%s"| G%d',
                $progression->from->id,
                $this->escapeMermaidLabel($this->getMermaidProgressionLabel($progression)),
                $progression->to->id
            );
        }

        foreach ($tournament->getMultiProgressions() as $progression) {
            if (!isset($groups[$progression->to->id])) {
                continue;
            }
            foreach ($progression->from as $from) {
                if (!isset($groups[$from->id])) {
                    continue;
                }

                $lines[] = sprintf(
                    '  G%d -->|"%s"| G%d',
                    $from->id,
                    $this->escapeMermaidLabel($this->getMermaidMultiProgressionLabel($progression)),
                    $progression->to->id
                );
            }
        }

        $lines[] = '  classDef planned fill:#f8f9fa,stroke:#adb5bd,color:#212529';
        $lines[] = '  classDef ready fill:#fff3cd,stroke:#ffca2c,color:#212529';
        $lines[] = '  classDef progressed fill:#d1e7dd,stroke:#198754,color:#0f5132';
        $lines[] = '  classDef partial fill:#cff4fc,stroke:#0dcaf0,color:#055160';
        $lines[] = '  classDef blocked fill:#f8d7da,stroke:#dc3545,color:#842029';

        return implode("\n", $lines);
    }

    /**
     * @return array<string,string>
     */
    public function getBracketStatusLegend(): array
    {
        return [
            'planned' => lang('Naplánováno', domain: 'tournament'),
            'ready' => lang('Připraveno k postupu', domain: 'tournament'),
            'progressed' => lang('Postoupeno / odehráno', domain: 'tournament'),
            'partial' => lang('Částečně obsazeno', domain: 'tournament'),
            'blocked' => lang('Blokováno výsledky', domain: 'tournament'),
        ];
    }

    private function getMermaidGroupStatus(Group $group): string
    {
        $incoming = array_merge($group->progressionsTo, $group->multiProgressionsTo);
        $incomingFilled = 0;
        $incomingTotal = 0;
        $blocked = false;
        foreach ($incoming as $progression) {
            foreach ($this->getProgressionFillState($progression) as $state) {
                $incomingTotal++;
                if ($state['filled']) {
                    $incomingFilled++;
                }
                if (!$state['filled'] && $state['blocked']) {
                    $blocked = true;
                }
            }
        }

        if ($blocked) {
            return 'blocked';
        }
        if ($incomingTotal > 0 && $incomingFilled > 0 && $incomingFilled < $incomingTotal) {
            return 'partial';
        }

        $playedGames = 0;
        $plannedGames = 0;
        foreach ($group->games as $game) {
            if (count($game->teams->filter(static fn(GameTeam $team) => isset($team->team))) < 2) {
                continue;
            }
            $plannedGames++;
            if ($game->hasScores()) {
                $playedGames++;
            }
        }

        $outgoing = array_merge($group->progressionsFrom, $group->multiProgressionsFrom);
        if (count($outgoing) > 0) {
            $outgoingStates = [];
            foreach ($outgoing as $progression) {
                $outgoingStates = [...$outgoingStates, ...$this->getProgressionFillState($progression)];
            }
            if (!empty($outgoingStates) && array_all($outgoingStates, static fn(array $state) => $state['filled'])) {
                return 'progressed';
            }
            if ($plannedGames > 0 && $playedGames === $plannedGames) {
                return 'ready';
            }
        }

        if ($plannedGames > 0 && $playedGames === $plannedGames) {
            return 'progressed';
        }

        return 'planned';
    }

    /**
     * @return array<int,array{key:int,filled:bool,blocked:bool}>
     */
    private function getProgressionFillState(Progression|MultiProgression $progression): array
    {
        $states = [];
        foreach ($progression->getKeys() as $key) {
            $states[$key] = ['key' => $key, 'filled' => false, 'blocked' => false];
        }
        foreach ($progression->to->games as $game) {
            foreach ($game->teams as $team) {
                if (!isset($states[$team->key])) {
                    continue;
                }
                if (isset($team->team)) {
                    $states[$team->key]['filled'] = true;
                }
                if ($game->hasScores()) {
                    $states[$team->key]['blocked'] = true;
                }
            }
        }
        return array_values($states);
    }

    private function getMermaidGroupLabel(Group $group): string
    {
        $gameCount = count($group->games);
        if ($gameCount === 0) {
            return $group->name;
        }

        return sprintf(
            '%s (%d %s)',
            $group->name,
            $gameCount,
            $gameCount === 1 ? lang('hra', domain: 'tournament') : lang('her', domain: 'tournament')
        );
    }

    private function getMermaidProgressionLabel(Progression $progression): string
    {
        return trim(
            implode(
                ', ',
                array_filter(
                    [
                        $this->getMermaidProgressionRangeLabel($progression->start, $progression->length),
                        $this->getMermaidProgressionPointsLabel($progression->points),
                    ],
                    static fn(string $label) => $label !== ''
                )
            )
        );
    }

    private function getMermaidMultiProgressionLabel(MultiProgression $progression): string
    {
        return trim(
            implode(
                ', ',
                array_filter(
                    [
                        $this->getMermaidProgressionRangeLabel($progression->start, $progression->length),
                        isset($progression->totalLength) ? sprintf(
                            'top %d/%d',
                            $progression->totalLength,
                            count($progression->from)
                        ) : '',
                        $this->getMermaidProgressionPointsLabel($progression->points),
                    ],
                    static fn(string $label) => $label !== ''
                )
            )
        );
    }

    private function getMermaidProgressionRangeLabel(?int $start, ?int $length): string
    {
        $start ??= 0;
        if ($length === null) {
            return sprintf('rank %d+', $start + 1);
        }
        if ($length === 1) {
            return sprintf('rank %d', $start + 1);
        }

        return sprintf('rank %d-%d', $start + 1, $start + $length);
    }

    private function getMermaidProgressionPointsLabel(int $points): string
    {
        if ($points === 0) {
            return '';
        }

        return sprintf('%+d pts', $points);
    }

    private function escapeMermaidLabel(string $label): string
    {
        return str_replace(
            ["\r", "\n", '"', '|', '[', ']', '<', '>', '&'],
            [' ', ' ', "'", '/', '(', ')', '(', ')', '+'],
            $label
        );
    }

    /**
     * @param TournamentPresetType         $type
     * @param Tournament                   $tournament
     * @param int                          $iterations
     * @param array<string,string|numeric> $args
     *
     * @return TournamentGenerator
     * @throws ValidationException
     */
    public function createTournamentFromPreset(
        TournamentPresetType $type,
        Tournament           $tournament,
        int                  $iterations = 1,
        array                $args = []
    ): TournamentGenerator
    {
        $tournamentRozlos = new TournamentGenerator();
        foreach ($tournament->teams as $team) {
            $tournamentRozlos->team($team->name, $team->id);
        }

        $tournamentRozlos->setPlay($tournament->gameLength)->setGameWait($tournament->gamePause);

        switch ($type) {
            case TournamentPresetType::ROUND_ROBIN:
                $group = $tournamentRozlos->round()->group('A');
                $group->setInGame($tournament->teamsInGame);
                $tournamentRozlos->splitTeams();
                break;
            case TournamentPresetType::TWO_GROUPS_ROBIN:
                $half = (int) floor(count($tournament->teams) / 4);
                $round1 = $tournamentRozlos->round(lang('Kvalifikace'));
                $round2 = $tournamentRozlos->round(lang('Finále'));
                $groupA = $round1->group('A');
                $groupB = $round1->group('B');
                $groupC = $round2->group('C');
                $groupD = $round2->group('D');
                $groupA->progression($groupC, 0, $half)->setPoints(50);
                $groupA->progression($groupD, $half);
                $groupB->progression($groupC, 0, $half)->setPoints(50);
                $groupB->progression($groupD, $half);
                $tournamentRozlos->splitTeams($round1);
                break;
            case TournamentPresetType::TWO_GROUPS_ROBIN_10:
                $half = (int) floor(count($tournament->teams) / 4);
                $round1 = $tournamentRozlos->round(lang('Kvalifikace'));
                $round2 = $tournamentRozlos->round(lang('Finále'));
                $groupA = $round1->group('A');
                $groupB = $round1->group('B');
                $groupC = $round2->group('C');
                $groupD = $round2->group('D');
                $groupA->progression($groupC, 0, $half)->setPoints(50);
                $groupA->progression($groupC, $half, 1)->setPoints(50);
                $groupA->progression($groupD, $half + 1);
                $groupB->progression($groupC, 0, $half)->setPoints(50);
                $groupB->progression($groupD, $half, 1);
                $groupB->progression($groupD, $half + 1);
                $tournamentRozlos->splitTeams($round1);
                break;
            case TournamentPresetType::BASE_ROUND_AND_BARRAGE:
                $this->prepareGamesBarrage(
                    $tournament,
                    $tournamentRozlos,
                    (int)($args['base_game_count'] ?? 3),
                    (int)($args['max_barrage_rounds'] ?? 4)
                );
                break;
            case TournamentPresetType::TWO_BASE_ROUND_AND_BARRAGE:
                $this->prepareTwoGroupsGamesBarrage(
                    $tournament,
                    $tournamentRozlos,
                    (int)($args['base_game_count'] ?? 3),
                    (int)($args['max_barrage_rounds'] ?? 4)
                );
                break;
        }

        if (
            $type !== TournamentPresetType::BASE_ROUND_AND_BARRAGE &&
            $type !== TournamentPresetType::TWO_BASE_ROUND_AND_BARRAGE
        ) {
            $tournamentRozlos->setIterationCount($iterations);
            $tournamentRozlos->genGamesSimulate();
        }

        return $tournamentRozlos;
    }

    /**
     * Prepares the games barrage for a tournament.
     *
     * The first round should be picked where each team plays exactly $baseGameCount games.
     * Then the barrage generates multiple rounds (maximum of $maxBarrageRounds) where each round will have -1 teams
     * already progressed from the first round and the last team will progress from the previous barrage round.
     * Only the first barrage round will be full right from the base round (with the teams that played the most poorly).
     *
     * @param Tournament          $tournament       The tournament object.
     * @param TournamentGenerator $tournamentRozlos The tournament generator object.
     * @param int                 $baseGameCount    The number of games each team should play. Default is 3.
     * @param int                 $maxBarrageRounds The maximum number of barrage rounds. Default is 4.
     *
     * @return void
     * @throws Exception
     *
     * @post $tournamentRozlos object will be populated with generated games.
     */
    private function prepareGamesBarrage(
        Tournament          $tournament,
        TournamentGenerator $tournamentRozlos,
        int                 $baseGameCount = 3,
        int                 $maxBarrageRounds = 4
    ): void
    {
        $teams = $tournamentRozlos->getTeams();
        shuffle($teams);

        $baseRound = $tournamentRozlos->round(lang('Základní skupina', context: 'tournament'));
        $baseGroup = $baseRound->group('A');
        $baseGroup->setInGame($tournament->teamsInGame);
        $baseGroup->addTeam(...$teams);
        $this->generateBarrageBaseGroupGames($baseGroup, $baseGroup->getTeams(), $tournament, $baseGameCount);
        $baseGroup->orderGames();

        $roundCounter = 1;
        $teamCount = count($teams);
        $teamsInGame = $tournament->teamsInGame;
        $eliminationsPerGame = $teamsInGame - 1;
        $preliminaryEliminations = ($teamCount - 1) % $eliminationsPerGame;
        $preliminarySurvivors = $preliminaryEliminations > 0 ? $teamsInGame - $preliminaryEliminations : 0;
        $barrageRounds = (int)(($teamCount - $preliminaryEliminations - 1) / $eliminationsPerGame);
        $placementPointStep = $this->getBarragePlacementPointStep(
            $tournament,
            $baseGameCount,
            $barrageRounds,
            $preliminaryEliminations > 0
        );
        $this->logger->debug(
            'Barrage progression setup',
            [
                'tournament' => $tournament->id,
                'teamCount' => $teamCount,
                'teamsInGame' => $teamsInGame,
                'eliminationsPerGame' => $eliminationsPerGame,
                'preliminaryEliminations' => $preliminaryEliminations,
                'preliminarySurvivors' => $preliminarySurvivors,
                'barrageRounds' => $barrageRounds,
                'placementPointStep' => $placementPointStep,
            ]
        );

        // If the team count cannot be reduced by regular barrage games, add one full preliminary game
        // where more than one team can progress. Example: 12 teams in 3-team games need bottom 3 -> top 2.
        $preliminaryGroup = null;
        $preliminaryProgression = null;
        if ($preliminaryEliminations > 0) {
            $preliminaryRound = $tournamentRozlos->round(lang('Předkolo', domain: 'tournament'));
            $alphabet = range('A', 'Z');
            $preliminaryGroup = $preliminaryRound->group($alphabet[$roundCounter])->setInGame($teamsInGame);
            $preliminaryProgression = $baseGroup->progression(
                $preliminaryGroup,
                $teamCount - $teamsInGame,
                $teamsInGame
            )->setPoints(0);

            $preliminaryTeams = [];
            for ($t = 0; $t < $teamsInGame; $t++) {
                $teamIndex = $teamCount - $teamsInGame + $t;
                $preliminaryTeams[] = $team = new BlankTeam(
                    $alphabet[$roundCounter] . $t,
                    $teams[$teamIndex],
                    $baseGroup,
                    $preliminaryProgression
                );
                $preliminaryGroup->addTeam($team);
            }
            $preliminaryGroup->game($preliminaryTeams);
            $roundCounter++;
        }

        $this->generateBarrageRounds(
            $tournament,
            $tournamentRozlos,
            $barrageRounds,
            $placementPointStep,
            $roundCounter,
            function (GeneratorGroup $group) use (
                $baseGroup,
                $barrageRounds,
                $eliminationsPerGame,
                $placementPointStep,
                $preliminaryGroup,
                $preliminarySurvivors,
                $teams,
                $teamCount,
                $teamsInGame,
                $tournament
            ): array {
                $slots = [];
                $baseTeamsInFirstRound = $teamsInGame - $preliminarySurvivors;
                if ($baseTeamsInFirstRound > 0) {
                    $baseStart = ($barrageRounds - 1) * $eliminationsPerGame;
                    $progression = $baseGroup->progression(
                        $group,
                        $baseStart,
                        $baseTeamsInFirstRound
                    )->setPoints($placementPointStep);
                    for ($t = 0; $t < $baseTeamsInFirstRound; $t++) {
                        $slots[] = $this->createBarrageSlot($baseGroup, $progression, $teams[$baseStart + $t]);
                    }
                }
                if ($preliminaryGroup !== null) {
                    for ($t = 0; $t < $preliminarySurvivors; $t++) {
                        $progression = $preliminaryGroup->progression($group, $t, 1)->setPoints(
                            $placementPointStep - $this->getTournamentPointsForPosition($t + 1, $tournament)
                        );
                        $slots[] = $this->createBarrageSlot(
                            $preliminaryGroup,
                            $progression,
                            $teams[$teamCount - $preliminarySurvivors + $t]
                        );
                    }
                }

                return $slots;
            },
            function (
                int            $roundIndex,
                int            $stage,
                GeneratorGroup $newGroup
            ) use (
                $baseGroup,
                $barrageRounds,
                $teams,
                $teamsInGame,
                $placementPointStep
            ): array {
                $baseStart = ($barrageRounds - 1 - $roundIndex) * ($teamsInGame - 1);
                $progression = $baseGroup->progression(
                    $newGroup,
                    $baseStart,
                    $teamsInGame - 1
                )->setPoints($stage * $placementPointStep);
                $slots = [];
                for ($t = 0; $t < ($teamsInGame - 1); $t++) {
                    $slots[] = $this->createBarrageSlot($baseGroup, $progression, $teams[$baseStart + $t]);
                }

                return [
                    'slots' => $slots,
                    'previousTeam' => $teams[$baseStart + count($slots)],
                ];
            }
        );
    }

    private function getBarragePlacementPointStep(
        Tournament $tournament,
        int        $baseGameCount,
        int        $barrageRounds,
        bool       $hasPreliminaryRound
    ): int
    {
        $maxGamePoints = max(
            $tournament->points->win,
            $tournament->points->draw,
            $tournament->points->second,
            $tournament->points->third,
            $tournament->points->loss
        );

        return (($baseGameCount + $barrageRounds + ($hasPreliminaryRound ? 1 : 0) + 1) * $maxGamePoints) + 1;
    }

    private function getTournamentPointsForPosition(int $position, Tournament $tournament): int
    {
        return match ($position) {
            1 => $tournament->points->win,
            2 => $tournament->teamsInGame > 2 ? $tournament->points->second : $tournament->points->loss,
            3 => $tournament->teamsInGame > 3 ? $tournament->points->third : $tournament->points->loss,
            default => $tournament->points->loss,
        };
    }

    /**
     * Generates games for the barrage base group.
     *
     * This method generates games for the base group, where each team plays exactly $baseGameCount games.
     * The algorithm aims to create fair games, where each player ideally plays the same opponent only once.
     * If it encounters an impossible game, it should include some form of rollback functionality.
     *
     * @param array<int|string,int>                   $teamGames          An array of team games counters.
     * @param array<int|string,GeneratorTeam> $teamIds Team objects indexed by their IDs.
     * @param Tournament                              $tournament         The tournament object.
     * @param array<int|string,array<int|string,int>> $teamGamesWithTeams An array representing the game matrix
     *                                                                    between teams. Each element is another array
     *                                                                    indexed by team IDs, which holds the number
     *                                                                    of games played between each pair of teams.
     * @param GeneratorGroup                          $baseGroup          The base group object.
     * @param int                                     $baseGameCount      The number of games each team should play.
     *
     * @return void
     * @throws Exception
     *
     * @post The games will be generated and added to the $baseGroup object.
     */
    private function generateBarrageBaseGroup(
        array          $teamGames,
        array          $teamIds,
        Tournament     $tournament,
        array          $teamGamesWithTeams,
        GeneratorGroup $baseGroup,
        int            $baseGameCount
    ): void
    {
        $totalGameSlots = count($teamGames) * $baseGameCount;
        if ($totalGameSlots % $tournament->teamsInGame !== 0) {
            throw new Exception('Cannot generate barrage base group with equal game counts.');
        }

        $remainingGames = array_map(static fn() => $baseGameCount, $teamGames);
        $schedule = $this->buildBarrageBaseSchedule(
            $remainingGames,
            $teamGamesWithTeams,
            $tournament->teamsInGame
        );
        if ($schedule === null) {
            throw new Exception('Cannot generate barrage base group with equal game counts.');
        }
        $schedule = $this->orderBarrageBaseSchedule($schedule);

        foreach ($schedule as $gameTeamIds) {
            $baseGroup->game(array_map(static fn(int|string $id) => $teamIds[$id], $gameTeamIds));
        }
    }

    /**
     * @param array<int|string,int> $remainingGames
     * @param array<int|string,array<int|string,int>> $teamGamesWithTeams
     *
     * @return array<int,array<int,int|string>>|null
     */
    private function buildBarrageBaseSchedule(
        array $remainingGames,
        array $teamGamesWithTeams,
        int   $teamsInGame
    ): ?array
    {
        $attempts = 0;

        return $this->buildBarrageBaseScheduleStep(
            $remainingGames,
            $teamGamesWithTeams,
            $teamsInGame,
            [],
            $attempts
        );
    }

    /**
     * @param array<int|string,int> $remainingGames
     * @param array<int|string,array<int|string,int>> $teamGamesWithTeams
     * @param array<int,array<int,int|string>> $schedule
     *
     * @return array<int,array<int,int|string>>|null
     */
    private function buildBarrageBaseScheduleStep(
        array $remainingGames,
        array $teamGamesWithTeams,
        int   $teamsInGame,
        array $schedule,
        int   &$attempts
    ): ?array
    {
        $attempts++;
        if ($attempts > 50000) {
            return null;
        }

        $remainingGames = array_filter($remainingGames, static fn(int $games) => $games > 0);
        if (count($remainingGames) === 0) {
            return $schedule;
        }

        $remainingSlots = array_sum($remainingGames);
        if (
            $remainingSlots % $teamsInGame !== 0 ||
            count($remainingGames) < $teamsInGame ||
            max($remainingGames) > ($remainingSlots / $teamsInGame)
        ) {
            return null;
        }

        arsort($remainingGames);
        $firstTeamId = array_key_first($remainingGames);
        $partnerIds = array_values(array_diff(array_keys($remainingGames), [$firstTeamId]));
        $partnerCombinations = $this->getBarrageTeamCombinations($partnerIds, $teamsInGame - 1);
        usort(
            $partnerCombinations,
            static function (array $a, array $b) use ($firstTeamId, $remainingGames, $teamGamesWithTeams): int {
                return [
                        self::getBarrageTeamSetPairCount([$firstTeamId, ...$a], $teamGamesWithTeams),
                        -array_sum(array_intersect_key($remainingGames, array_flip($a))),
                    ] <=> [
                        self::getBarrageTeamSetPairCount([$firstTeamId, ...$b], $teamGamesWithTeams),
                        -array_sum(array_intersect_key($remainingGames, array_flip($b))),
                    ];
            }
        );

        foreach ($partnerCombinations as $partnerCombination) {
            $teamIds = [$firstTeamId, ...$partnerCombination];
            $nextRemainingGames = $remainingGames;
            foreach ($teamIds as $teamId) {
                $nextRemainingGames[$teamId]--;
            }
            $nextTeamGamesWithTeams = $teamGamesWithTeams;
            foreach ($teamIds as $teamId) {
                foreach ($teamIds as $opponentId) {
                    if ($teamId === $opponentId) {
                        continue;
                    }
                    $nextTeamGamesWithTeams[$teamId][$opponentId]++;
                }
            }

            $result = $this->buildBarrageBaseScheduleStep(
                $nextRemainingGames,
                $nextTeamGamesWithTeams,
                $teamsInGame,
                [...$schedule, $teamIds],
                $attempts
            );
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param list<int|string> $teamIds
     *
     * @return list<list<int|string>>
     */
    private function getBarrageTeamCombinations(array $teamIds, int $count): array
    {
        if ($count === 0) {
            return [[]];
        }

        $combinations = [];
        for ($i = 0, $length = count($teamIds); $i <= $length - $count; $i++) {
            $teamId = $teamIds[$i];
            $remainingTeamIds = array_slice($teamIds, $i + 1);
            foreach ($this->getBarrageTeamCombinations($remainingTeamIds, $count - 1) as $combination) {
                $combinations[] = [$teamId, ...$combination];
            }
        }

        return $combinations;
    }

    /**
     * @param array<int,array<int,int|string>> $schedule
     *
     * @return array<int,array<int,int|string>>
     */
    private function orderBarrageBaseSchedule(array $schedule): array
    {
        $orderedSchedule = [];
        $lastPlayed = [];

        while (count($schedule) > 0) {
            $roundIndex = count($orderedSchedule);
            $previousGame = $orderedSchedule[$roundIndex - 1] ?? [];
            $twoGamesAgo = $orderedSchedule[$roundIndex - 2] ?? [];

            uasort(
                $schedule,
                static function (array $a, array $b) use ($previousGame, $twoGamesAgo, $lastPlayed, $roundIndex): int {
                    return self::getBarrageBaseScheduleOrderScore(
                            $a,
                            $previousGame,
                            $twoGamesAgo,
                            $lastPlayed,
                            $roundIndex
                        ) <=> self::getBarrageBaseScheduleOrderScore(
                            $b,
                            $previousGame,
                            $twoGamesAgo,
                            $lastPlayed,
                            $roundIndex
                        );
                }
            );

            $nextKey = array_key_first($schedule);
            $nextGame = $schedule[$nextKey];
            unset($schedule[$nextKey]);
            $orderedSchedule[] = $nextGame;
            foreach ($nextGame as $teamId) {
                $lastPlayed[$teamId] = $roundIndex;
            }
        }

        return $orderedSchedule;
    }

    /**
     * @param array<int,int|string> $game
     * @param array<int,int|string> $previousGame
     * @param array<int,int|string> $twoGamesAgo
     * @param array<int|string,int> $lastPlayed
     */
    private static function getBarrageBaseScheduleOrderScore(
        array $game,
        array $previousGame,
        array $twoGamesAgo,
        array $lastPlayed,
        int   $roundIndex
    ): int
    {
        $previousOverlap = count(array_intersect($game, $previousGame));
        $twoGamesAgoOverlap = count(array_intersect($game, $twoGamesAgo));
        $waitScore = 0;
        foreach ($game as $teamId) {
            $waitScore += $roundIndex - ($lastPlayed[$teamId] ?? -count($lastPlayed) - 1);
        }

        return ($previousOverlap * 10000) + ($twoGamesAgoOverlap * 1000) - $waitScore;
    }

    /**
     * @param list<int|string> $teamIds
     * @param array<int|string,array<int|string,int>> $teamGamesWithTeams
     */
    private static function getBarrageTeamSetPairCount(array $teamIds, array $teamGamesWithTeams): int
    {
        $pairCount = 0;
        foreach ($teamIds as $index => $teamId) {
            foreach (array_slice($teamIds, $index + 1) as $opponentId) {
                $pairCount += $teamGamesWithTeams[$teamId][$opponentId] ?? 0;
            }
        }

        return $pairCount;
    }

    private function prepareTwoGroupsGamesBarrage(
        Tournament          $tournament,
        TournamentGenerator $tournamentRozlos,
        int                 $baseGameCount = 3,
        int                 $maxBarrageRounds = 4
    ): void
    {
        $teams = $tournamentRozlos->getTeams();
        shuffle($teams);

        $baseRound = $tournamentRozlos->round(lang('Základní skupina', context: 'tournament'));
        $baseRound->addTeam(...$teams);
        $baseGroup1 = $baseRound->group('A');
        $baseGroup2 = $baseRound->group('B');
        $baseGroup1->setInGame($tournament->teamsInGame);
        $baseGroup2->setInGame($tournament->teamsInGame);
        $baseRound->splitTeams();

        // Generate games for both base groups
        foreach ([$baseGroup1, $baseGroup2] as $baseGroup) {
            $this->generateBarrageBaseGroupGames($baseGroup, $baseGroup->getTeams(), $tournament, $baseGameCount);
        }

        // Sort the games to minimize one team playing multiple games back to back
        $baseGroup1->orderGames();
        $baseGroup2->orderGames();

        // Generate playoff round
        $teamsInGame = $tournament->teamsInGame;
        $groupTeamCount = min(count($baseGroup1->getTeams()), count($baseGroup2->getTeams()));
        $barrageRounds = max(1, min($groupTeamCount - $teamsInGame + 1, $maxBarrageRounds));
        $placementPointStep = $this->getBarragePlacementPointStep(
            $tournament,
            $baseGameCount,
            $barrageRounds + 1,
            false
        );
        $this->logger->debug(
            'Two groups barrage progression setup',
            [
                'tournament' => $tournament->id,
                'teamCount' => count($teams),
                'teamsInGame' => $teamsInGame,
                'groupTeamCount' => $groupTeamCount,
                'barrageRounds' => $barrageRounds,
                'placementPointStep' => $placementPointStep,
            ]
        );

        $playoff = $tournamentRozlos->round(lang('Play-off'));
        $playoffGroup1 = $playoff->group('C')->setInGame($teamsInGame);
        $playoffGroup2 = $playoff->group('D')->setInGame($teamsInGame);
        // Pull the first playoff teams from the lowest base standings that can still enter the barrage.
        $progression1 = $baseGroup1->progression(
            $playoffGroup1,
            $barrageRounds - 1,
            $teamsInGame,
        )->setPoints($placementPointStep);
        $progression2 = $baseGroup2->progression(
            $playoffGroup2,
            $barrageRounds - 1,
            $teamsInGame,
        )->setPoints($placementPointStep);
        $group1Teams = [];
        $group2Teams = [];
        for ($t = 0; $t < $teamsInGame; $t++) {
            $group1Teams[] = $team = new BlankTeam('C' . $t, $teams[$t], $baseGroup1, $progression1);
            $playoffGroup1->addTeam($team);
            $group2Teams[] = $team = new BlankTeam('D' . $t, $teams[$t], $baseGroup2, $progression2);
            $playoffGroup2->addTeam($team);
        }
        $playoffGroup1->game($group1Teams);
        $playoffGroup2->game($group2Teams);

        $roundCounter = 3;
        $this->generateBarrageRounds(
            $tournament,
            $tournamentRozlos,
            $barrageRounds,
            $placementPointStep,
            $roundCounter,
            function (GeneratorGroup $group) use (
                $playoffGroup1,
                $playoffGroup2,
                $placementPointStep,
                $teams,
                $tournament
            ): array {
                $progression1 = $playoffGroup1->progression($group, 0, 1)->setPoints(
                    $placementPointStep - $tournament->points->win
                );
                $progression2 = $playoffGroup2->progression($group, 0, 1)->setPoints(
                    $placementPointStep - $tournament->points->win
                );
                $progression3 = $group->multiProgression([$playoffGroup1, $playoffGroup2], 1, 1, 1)->setPoints(
                    $placementPointStep - $this->getTournamentPointsForPosition(2, $tournament)
                );

                return [
                    $this->createBarrageSlot($playoffGroup1, $progression1, $teams[0]),
                    $this->createBarrageSlot($playoffGroup2, $progression2, $teams[1]),
                    $this->createBarrageSlot($playoffGroup1, $progression3, $teams[2]),
                ];
            },
            function (
                int            $roundIndex,
                int            $stage,
                GeneratorGroup $newGroup
            ) use (
                $baseGroup1,
                $baseGroup2,
                $barrageRounds,
                $placementPointStep,
                $teams
            ): array {
                $points = $stage * $placementPointStep;
                $progression1 = $baseGroup1->progression(
                    $newGroup,
                    $barrageRounds - $roundIndex - 1,
                    1
                )->setPoints($points);
                $progression2 = $baseGroup2->progression(
                    $newGroup,
                    $barrageRounds - $roundIndex - 1,
                    1
                )->setPoints($points);

                return [
                    'slots' => [
                        $this->createBarrageSlot($baseGroup1, $progression1, $teams[0]),
                        $this->createBarrageSlot($baseGroup2, $progression2, $teams[1]),
                    ],
                    'previousTeam' => $teams[2],
                ];
            }
        );
    }

    /**
     * @param GeneratorTeam[] $teams
     *
     * @throws Exception
     */
    private function generateBarrageBaseGroupGames(
        GeneratorGroup $baseGroup,
        array          $teams,
        Tournament     $tournament,
        int            $baseGameCount
    ): void
    {
        /** @var array<int|string,GeneratorTeam> $teamIds */
        $teamIds = [];
        /** @var array<int|string,int> $teamGames */
        $teamGames = [];
        /** @var array<int|string,array<int|string,int>> $teamGamesWithTeams */
        $teamGamesWithTeams = [];
        foreach ($teams as $team) {
            $teamIds[$team->getId()] = $team;
            $teamGames[$team->getId()] = 0;
            $teamGamesWithTeams[$team->getId()] = [];
            foreach ($teams as $team2) {
                $teamGamesWithTeams[$team->getId()][$team2->getId()] = 0;
            }
        }

        $this->generateBarrageBaseGroup(
            $teamGames,
            $teamIds,
            $tournament,
            $teamGamesWithTeams,
            $baseGroup,
            $baseGameCount
        );
    }

    /**
     * @param callable(GeneratorGroup):array $firstRoundSlotFactory
     * @param callable(int,int,GeneratorGroup):array $nextRoundSlotFactory
     */
    private function generateBarrageRounds(
        Tournament          $tournament,
        TournamentGenerator $tournamentRozlos,
        int                 $barrageRounds,
        int                 $placementPointStep,
        int                 $roundCounter,
        callable            $firstRoundSlotFactory,
        callable            $nextRoundSlotFactory
    ): void
    {
        $roundNames = $this->getBarrageRoundNames();
        $otherRoundName = lang('Předkolo', domain: 'tournament');
        $alphabet = range('A', 'Z');
        $teamsInGame = $tournament->teamsInGame;

        $round = $tournamentRozlos->round($roundNames[$barrageRounds - 1] ?? $otherRoundName);
        $group = $round->group($alphabet[$roundCounter])->setInGame($teamsInGame);
        $group->game(
            $this->addBarrageSlotsToGroup(
                $group,
                $firstRoundSlotFactory($group),
                $alphabet[$roundCounter]
            )
        );

        for ($i = 1; $i < $barrageRounds; $i++) {
            $stage = $i + 1;
            $newRound = $tournamentRozlos->round($roundNames[$barrageRounds - 1 - $i] ?? $otherRoundName);
            $newGroup = $newRound->group($alphabet[$roundCounter + $i])->setInGame($teamsInGame);
            $roundSetup = $nextRoundSlotFactory($i, $stage, $newGroup);
            $newGroupTeams = $this->addBarrageSlotsToGroup(
                $newGroup,
                $roundSetup['slots'],
                $alphabet[$roundCounter + $i]
            );

            $progression = $group->progression($newGroup, 0, 1)->setPoints(
                $placementPointStep - $tournament->points->win
            );
            $newGroupTeams[] = $team = new BlankTeam(
                $alphabet[$roundCounter + $i] . count($newGroupTeams),
                $roundSetup['previousTeam'],
                $group,
                $progression
            );
            $newGroup->addTeam($team);
            $newGroup->game($newGroupTeams);
            $group = $newGroup;
        }

        $placementRound = $tournamentRozlos->round(lang('Konečné pořadí', domain: 'tournament'));
        $placementGroup = $placementRound->group($alphabet[$roundCounter + $barrageRounds])->setInGame($teamsInGame);
        $group->progression($placementGroup, 0, 1)->setPoints(3 * $placementPointStep);
        $group->progression($placementGroup, 1, 1)->setPoints(2 * $placementPointStep);
        $group->progression($placementGroup, 2, 1)->setPoints($placementPointStep);
    }

    /**
     * @return list<string>
     */
    private function getBarrageRoundNames(): array
    {
        return [
            lang('Finále', domain: 'tournament'),
            lang('Semifinále', domain: 'tournament'),
            lang('Čtvrtfinále', domain: 'tournament'),
            lang('Osmifinále', domain: 'tournament'),
            lang('Šestnáctifinále', domain: 'tournament'),
        ];
    }

    /**
     * @param list<array{from:GeneratorGroup,progression:ProgressionInterface,team:GeneratorTeam}> $slots
     *
     * @return list<BlankTeam>
     */
    private function addBarrageSlotsToGroup(GeneratorGroup $group, array $slots, string $keyPrefix): array
    {
        $groupTeams = [];
        foreach ($slots as $slot) {
            $groupTeams[] = $team = new BlankTeam(
                $keyPrefix . count($groupTeams),
                $slot['team'],
                $slot['from'],
                $slot['progression']
            );
            $group->addTeam($team);
        }

        return $groupTeams;
    }

    /**
     * @return array{from:GeneratorGroup,progression:ProgressionInterface,team:GeneratorTeam}
     */
    private function createBarrageSlot(
        GeneratorGroup       $from,
        ProgressionInterface $progression,
        GeneratorTeam        $team
    ): array
    {
        return [
            'from' => $from,
            'progression' => $progression,
            'team' => $team,
        ];
    }

    public function progress(Tournament $tournament): int {
        $progressed = 0;
        $tournamentRozlos = $this->reconstructTournament($tournament);

        foreach ($tournamentRozlos->getGroups() as $groupRozlos) {
            bdump($groupRozlos);
            bdump($groupRozlos->isPlayed());

            // If the group is not finished, there is no need to progress teams
            if (!$groupRozlos->isPlayed()) {
                continue;
            }

            $groupChanged = false;

            $teams = $groupRozlos->getTeams(true);
            $teamsInfo = [];
            foreach ($teams as $team) {
                $teamsInfo[$team->getId()] = [
                    'name'   => $team->getName(),
                    'score'  => $team->sumScore([$groupRozlos->getId()]),
                    'points' => $team->sumPoints([$groupRozlos->getId()]),
                ];
            }
            bdump($teamsInfo);

            // Get all progressions from this group
            $group = Group::get($groupRozlos->getId());
            /** @var Progression[] $progressions */
            $progressions = array_merge(
                $group->progressionsFrom,
                $group->multiProgressionsFrom
            );
            foreach ($progressions as $progression) {
                $progressionRozlos = $progression->progression;

                // Skip if already progressed
                if (!isset($progressionRozlos) || $progressionRozlos->isProgressed()) {
                    continue;
                }

                $groupChanged = true;

                // Do the progression
                $progressionRozlos->progress();
                $progressedGeneratorTeams = [];
                foreach ($progressionRozlos->getProgressedTeams() as $team) {
                    $progressedGeneratorTeams[] = [
                        'id' => $team->getId(),
                        'name' => $team->getName(),
                    ];
                }

                // Find progressed teams
                /** @var Team[] $progressedTeams */
                $progressedTeams = [];
                $progressedTeamIdsByKey = [];
                $keys = $progression->getKeys();
                foreach ($progressionRozlos->getProgressedTeams() as $team) {
                    $key = array_shift($keys);
                    $progressedTeams[$key] = Team::get($team->getId());
                    $progressedTeamIdsByKey[$key] = $team->getId();
                    if (empty($keys)) {
                        break;
                    }
                }
                $this->logger->debug(
                    'TournamentProvider::progress applied progression',
                    [
                        'tournament' => $tournament->id,
                        'progressionId' => $progression->id,
                        'fromGroup' => $group->id,
                        'toGroup' => $progression->to->id,
                        'start' => $progression->start,
                        'length' => $progression->length,
                        'teams' => $progressedGeneratorTeams,
                        'teamIdsByKey' => $progressedTeamIdsByKey,
                    ]
                );

                // Update the games
                $to = $progression->to;
                foreach ($to->games as $game) {
                    $changed = false;
                    // Assign progressed teams to games
                    foreach ($game->teams as $team) {
                        if (isset($progressedTeams[$team->key])) {
                            $team->team = $progressedTeams[$team->key];
                            $changed = true;
                        }
                    }
                    if ($changed) {
                        $game->save();
                    }
                }
            }

            if (!$groupChanged) {
                continue; // Do not update the teams if no progression occured
            }

            $progressed++;

            // Update group's teams
            foreach ($groupRozlos->getTeams() as $teamRozlos) {
                $team = Team::get($teamRozlos->getId());
                if (!isset($team)) {
                    continue;
                }
                // Update points
                $team->points = $teamRozlos->getSumPoints();
                $team->save();
            }
        }

        $this->cleanTournamentCache($tournament);

        return $progressed;
    }

    public function undoProgression(Tournament $tournament, string $type, int $progressionId): int
    {
        $this->logger->debug(
            'TournamentProvider::undoProgression started',
            [
                'tournament' => $tournament->id,
                'type' => $type,
                'progressionId' => $progressionId,
            ]
        );
        $progression = $this->getProgressionForTournament($tournament, $type, $progressionId);
        $changed = $this->clearProgressionTeams($progression);

        if ($changed > 0) {
            $this->logger->debug(
                'TournamentProvider::undoProgression recalculating points',
                [
                    'tournament' => $tournament->id,
                    'type' => $type,
                    'progressionId' => $progressionId,
                    'changed' => $changed,
                ]
            );
            $this->recalcTeamPoints($tournament);
            $this->cleanTournamentCache($tournament);
        }

        $this->logger->debug(
            'TournamentProvider::undoProgression finished',
            [
                'tournament' => $tournament->id,
                'type' => $type,
                'progressionId' => $progressionId,
                'changed' => $changed,
            ]
        );
        return $changed;
    }

    public function setProgressionTeam(
        Tournament $tournament,
        string     $type,
        int        $progressionId,
        int        $key,
        Team       $team
    ): int
    {
        if ($team->tournament->id !== $tournament->id) {
            throw new RuntimeException(lang('Tým nepatří do tohoto turnaje', domain: 'tournament'));
        }

        $progression = $this->getProgressionForTournament($tournament, $type, $progressionId);
        if (!in_array($key, $progression->getKeys(), true)) {
            throw new RuntimeException(lang('Vybraný slot nepatří do tohoto postupu', domain: 'tournament'));
        }

        foreach ($progression->to->games as $game) {
            foreach ($game->teams as $gameTeam) {
                if ($gameTeam->key !== $key && $gameTeam->team?->id === $team->id) {
                    throw new RuntimeException(lang('Tým už je v cílové skupině zařazený', domain: 'tournament'));
                }
            }
        }

        $changed = 0;
        foreach ($progression->to->games as $game) {
            $gameChanged = false;
            foreach ($game->teams as $gameTeam) {
                if ($gameTeam->key !== $key) {
                    continue;
                }
                $this->assertProgressionSlotEditable($gameTeam);
                if ($gameTeam->team?->id !== $team->id) {
                    $gameTeam->team = $team;
                    $gameChanged = true;
                    $changed++;
                }
            }
            if ($gameChanged) {
                $game->save();
            }
        }

        if ($changed > 0) {
            $this->recalcTeamPoints($tournament);
            $this->cleanTournamentCache($tournament);
        }

        return $changed;
    }

    private function getProgressionForTournament(
        Tournament $tournament,
        string     $type,
        int        $progressionId
    ): Progression|MultiProgression
    {
        $progression = match ($type) {
            'progression' => Progression::get($progressionId),
            'multi' => MultiProgression::get($progressionId),
            default => throw new RuntimeException(lang('Neplatný typ postupu', domain: 'tournament')),
        };

        if ($progression->tournament->id !== $tournament->id) {
            throw new RuntimeException(lang('Postup nepatří do tohoto turnaje', domain: 'tournament'));
        }

        return $progression;
    }

    private function clearProgressionTeams(Progression|MultiProgression $progression): int
    {
        $keys = $progression->getKeys();
        $changed = 0;

        foreach ($progression->to->games as $game) {
            $gameChanged = false;
            foreach ($game->teams as $gameTeam) {
                if (!in_array($gameTeam->key, $keys, true)) {
                    continue;
                }
                $this->assertProgressionSlotEditable($gameTeam);
                if (isset($gameTeam->team)) {
                    $gameTeam->team = null;
                    $gameChanged = true;
                    $changed++;
                }
            }
            if ($gameChanged) {
                $game->save();
            }
        }

        $this->logger->debug(
            'TournamentProvider::clearProgressionTeams finished',
            [
                'progressionId' => $progression->id,
                'changed' => $changed,
            ]
        );
        return $changed;
    }

    private function assertProgressionSlotEditable(GameTeam $gameTeam): void
    {
        if (
            $gameTeam->game->hasScores()
        ) {
            $this->logger->warning(
                'TournamentProvider::assertProgressionSlotEditable blocked slot',
                [
                    'game' => $gameTeam->game->id,
                    'gameCode' => $gameTeam->game->code,
                    'gameTeam' => $gameTeam->id,
                    'key' => $gameTeam->key,
                    'team' => $gameTeam->team?->id,
                    'score' => $gameTeam->score,
                    'points' => $gameTeam->points,
                    'position' => $gameTeam->position,
                    'hasScores' => $gameTeam->game->hasScores(),
                ]
            );
            throw new RuntimeException(
                lang('Postup nelze změnit, protože cílová hra už má uložené výsledky', domain: 'tournament')
            );
        }
    }

    private function cleanTournamentCache(Tournament $tournament): void
    {
        $this->cache->clean(
            [
                $this->cache::Tags => ['tournament/' . $tournament->id . '/group/teams'],
            ]
        );
    }

    public function reconstructTournament(Tournament $tournament): TournamentGenerator {
        $tournamentGenerator = new TournamentGenerator($tournament->name);

        $tournamentGenerator
            ->setPlay($tournament->gameLength)
            ->setGameWait($tournament->gamePause);
        $teams = [];
        foreach ($tournament->teams as $team) {
            $teams[$team->id] = $tournamentGenerator->team($team->name, $team->id);
        }

        bdump($teams);

        $rounds = [];
        $groups = [];
        $games = [];
        $groupResults = [];
        foreach ($tournament->groups as $group) {
            if (!isset($rounds[$group->round])) {
                $rounds[$group->round] = $tournamentGenerator->round($group->round);
            }
            $groups[$group->id] = $rounds[$group->round]->group(
                str_replace($group->round . ' - ', '', $group->name),
                $group->id
            )
                                                        ->setInGame($tournament->teamsInGame)
                                                        ->setWinPoints($tournament->points->win)
                                                        ->setDrawPoints($tournament->points->draw)
                                                        ->setSecondPoints($tournament->points->second)
                                                        ->setThirdPoints($tournament->points->third)
                                                        ->setLostPoints($tournament->points->loss);

            foreach ($group->games as $game) {
                if (count($game->teams->filter(static fn($team) => isset($team->team))) < 2) {
                    continue; // Skip planned games without any teams
                }
                $gameTeams = [];
                $resultRows = [];
                $full = true;
                foreach ($game->teams as $team) {
                    if (!isset($team->team) || !isset($teams[$team->team->id])) {
                        $full = false;
                        continue;
                    }
                    $gameTeams[] = $teams[$team->team->id];
                    if (isset($team->score) || isset($team->points)) {
                        $resultRows[] = $team;
                        $groupResults[$group->id][$team->team->id] ??= [
                            'score' => 0,
                            'points' => 0,
                        ];
                        $groupResults[$group->id][$team->team->id]['score'] += $team->score ?? 0;
                        $groupResults[$group->id][$team->team->id]['points'] += $team->points ?? 0;
                    }
                }
                $groups[$group->id]->addTeam(...$gameTeams);
                if (!$full) {
                    continue;
                }
                $games[$game->id] = $groups[$group->id]->game($gameTeams)->setId($game->id);
                if (count($resultRows) > 0) {
                    usort(
                        $resultRows,
                        static function (GameTeam $a, GameTeam $b): int {
                            if (isset($a->position, $b->position)) {
                                return $a->position <=> $b->position;
                            }
                            return ($b->score ?? 0) <=> ($a->score ?? 0);
                        }
                    );
                    $results = [];
                    foreach ($resultRows as $team) {
                        if (isset($team->team)) {
                            $results[$team->team->id] = $team->score ?? 0;
                        }
                    }
                    $games[$game->id]->setResults($results);
                }
            }

            foreach ($groupResults[$group->id] ?? [] as $teamId => $result) {
                if (!isset($teams[$teamId], $teams[$teamId]->groupResults[$group->id])) {
                    continue;
                }
                $currentScore = $teams[$teamId]->groupResults[$group->id]['score'];
                $currentPoints = $teams[$teamId]->groupResults[$group->id]['points'];
                $teams[$teamId]->groupResults[$group->id]['points'] = $result['points'];
                $teams[$teamId]->groupResults[$group->id]['score'] = $result['score'];
                $teams[$teamId]->addScore($result['score'] - $currentScore);
                $teams[$teamId]->addPoints($result['points'] - $currentPoints);
            }
        }

        bdump($games);

        foreach ($tournament->getProgressions() as $progression) {
            if (!isset($progression->from)) {
                continue;
            }
            $from = $groups[$progression->from->id];
            $to = $groups[$progression->to->id];
            $progressionRozlos = $from->progression($to, $progression->start, $progression->length)->setPoints(
                $progression->points
            );
            // TODO: Reconstruct filters

            // Check if not already progressed
            $keys = $progression->getKeys();
            $filledKeys = [];
            foreach ($progression->to->games as $game) {
                foreach ($game->teams as $team) {
                    if (!isset($team->team) || !in_array($team->key, $keys, true)) {
                        continue;
                    }
                    $filledKeys[] = $team->key;
                    unset($keys[array_search($team->key, $keys)]);
                    if (empty($keys)) {
                        break;
                    }
                }
                if (empty($keys)) {
                    break;
                }
            }
            if (empty($keys)) {
                $this->logger->debug(
                    'TournamentProvider::reconstructTournament restored completed progression',
                    [
                        'tournament' => $tournament->id,
                        'progressionId' => $progression->id,
                        'fromGroup' => $progression->from->id,
                        'toGroup' => $progression->to->id,
                    ]
                );
            }
            $progressionRozlos->setProgressed(empty($keys));
            $progression->progression = $progressionRozlos;
        }

        foreach ($tournament->getMultiProgressions() as $progression) {
            if (empty($progression->from)) {
                continue;
            }

            $from = [];
            foreach ($progression->from as $group) {
                $from[] = $groups[$group->id];
            }
            $to = $groups[$progression->to->id];
            $progressionRozlos = $to->multiProgression(
                $from,
                $progression->start,
                $progression->length,
                $progression->totalLength,
                $progression->totalStart
            )->setPoints($progression->points);
            // Check if not already progressed
            $keys = $progression->getKeys();
            $filledKeys = [];
            foreach ($progression->to->games as $game) {
                foreach ($game->teams as $team) {
                    if (!isset($team->team) || !in_array($team->key, $keys, true)) {
                        continue;
                    }
                    $filledKeys[] = $team->key;
                    unset($keys[array_search($team->key, $keys)]);
                    if (empty($keys)) {
                        break;
                    }
                }
                if (empty($keys)) {
                    break;
                }
            }
            if (empty($keys)) {
                $this->logger->debug(
                    'TournamentProvider::reconstructTournament restored completed multi progression',
                    [
                        'tournament' => $tournament->id,
                        'progressionId' => $progression->id,
                        'fromGroups' => array_map(
                            static fn(Group $group) => $group->id,
                            iterator_to_array($progression->from)
                        ),
                        'toGroup' => $progression->to->id,
                    ]
                );
            }
            $progressionRozlos->setProgressed(empty($keys));
            $progression->progression = $progressionRozlos;
        }

        return $tournamentGenerator;
    }

    public function recalcTeamPoints(Tournament $tournament): void {
        $this->logger->debug('TournamentProvider::recalcTeamPoints started', ['tournament' => $tournament->id ?? null]);
        $teams = $tournament->teams;
        $progressions = array_merge($tournament->getProgressions(), $tournament->getMultiProgressions());
        /** @var array<int,int> $points Sum points for games for each team */
        $points = DB::select(GameTeam::TABLE, 'id_team, SUM(points) as points')->groupBy('id_team')->fetchPairs(
            'id_team',
            'points',
            false
        );
        foreach ($teams as $team) {
            $team->points = $points[$team->id] ?? 0;

            // Check progressions
            $keys = $team->groupKeys;
            foreach ($progressions as $progression) {
                $progressionKeys = $progression->getKeys();
                if (in_array($keys[$progression->to->id] ?? null, $progressionKeys, true)) {
                    $team->points += $progression->points;
                    continue;
                }

                if (
                    !empty($progressionKeys) ||
                    !$progression instanceof Progression ||
                    !isset($progression->from) ||
                    count($progression->to->games) > 0
                ) {
                    continue;
                }

                $progressedTeams = array_slice(
                    $progression->from->getTeamsSorted(),
                    $progression->start ?? 0,
                    $progression->length
                );
                foreach ($progressedTeams as $progressedTeam) {
                    if ($progressedTeam->id === $team->id) {
                        $team->points += $progression->points;
                        break;
                    }
                }
            }

            // Save changes
            $team->save();
        }
        $this->logger->debug(
            'TournamentProvider::recalcTeamPoints finished',
            ['tournament' => $tournament->id ?? null, 'teams' => count($teams)]
        );
    }
}
