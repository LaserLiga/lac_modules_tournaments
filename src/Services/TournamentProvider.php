<?php

namespace LAC\Modules\Tournament\Services;

use App\GameModels\Game\Enums\GameModeType;
use App\Services\LaserLiga\LigaApi;
use App\Services\LaserLiga\PlayerProvider;
use DateTimeImmutable;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use LAC\Modules\Tournament\Models\Game;
use LAC\Modules\Tournament\Models\GameTeam;
use LAC\Modules\Tournament\Models\Group;
use LAC\Modules\Tournament\Models\League;
use LAC\Modules\Tournament\Models\Player;
use LAC\Modules\Tournament\Models\PlayerSkill;
use LAC\Modules\Tournament\Models\Team;
use LAC\Modules\Tournament\Models\Tournament;
use LAC\Modules\Tournament\Models\TournamentPresetType;
use Lsr\Core\Caching\Cache;
use Lsr\Core\DB;
use Lsr\Core\Exceptions\ValidationException;
use Lsr\Logging\Logger;
use TournamentGenerator\BlankTeam;
use TournamentGenerator\Group as GeneratorGroup;
use TournamentGenerator\Team as GeneratorTeam;
use TournamentGenerator\Tournament as TournamentGenerator;

class TournamentProvider
{
    private Logger $logger;

    public function __construct(
        private readonly LigaApi        $api,
        private readonly PlayerProvider $playerProvider,
        private readonly Cache $cache,
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
            /** @var array{id:int,name:string,image:string|null,description:string|null}[] $leagues */
            $leagues = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $this->logger->debug('Got ' . count($leagues) . ' leagues');
            bdump($leagues);
            foreach ($leagues as $league) {
                $leagueLocal = League::getByPublicId($league['id']);
                if (!isset($leagueLocal)) {
                    $leagueLocal = new League();
                }
                $leagueLocal->idPublic = $league['id'];
                $leagueLocal->name = $league['name'];
                $leagueLocal->description = $league['description'];
                $leagueLocal->image = $league['image'];
                $leagueLocal->save();
                try {
                    $this->logger->debug('Saving league - ' . json_encode($leagueLocal, JSON_THROW_ON_ERROR));
                } catch (JsonException $e) {
                    $this->logger->exception($e);
                }
            }

            // Sync tournaments
            $response = $this->api->get('/api/tournament');
            /** @var array{id:int,name:string,image:string|null,description:string|null,league:null|array{id:int,name:string},format:string,teamSize:int,subCount:int,active:bool,start:array{date:string,timezone:string}|string,end:null|array{date:string,timezone:string}|string}[] $tournaments */
            $tournaments = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            foreach ($tournaments as $tournament) {
                $tournamentLocal = Tournament::getByPublicId($tournament['id']);
                if (!isset($tournamentLocal)) {
                    $tournamentLocal = new Tournament();
                }
                $tournamentLocal->idPublic = $tournament['id'];
                $tournamentLocal->name = $tournament['name'];
                $tournamentLocal->description = $tournament['description'];
                $tournamentLocal->image = $tournament['image'];
                $tournamentLocal->format = GameModeType::from($tournament['format']);
                $tournamentLocal->teamSize = $tournament['teamSize'];
                $tournamentLocal->subCount = $tournament['subCount'];
                $tournamentLocal->active = $tournament['active'];
                $tournamentLocal->start = new DateTimeImmutable(is_string($tournament['start']) ? $tournament['start'] : $tournament['start']['date']);
                $tournamentLocal->end = isset($tournament['end']) ? new DateTimeImmutable(
                    (is_string($tournament['end']) ? $tournament['end'] : $tournament['end']['date'])
                ) : null;
                if (isset($tournament['league']['id'])) {
                    $tournamentLocal->league = League::getByPublicId($tournament['league']['id']);
                }
                $tournamentLocal->save();

                $response = $this->api->get(
                    '/api/tournament/' . $tournamentLocal->idPublic . '/teams',
                    ['withPlayers' => '1']
                );
                /** @var array{id:int,name:string,image:string|null,players:array{id:int,nickname:string,name:string|null,surname:string|null,captain:bool,sub:bool,email:string|null,phone:string|null,skill:string,birthYear:int|null,image:string|null,user:null|array{id:int,nickname:string,code:string,email:string}}[]}[] $teams */
                $teams = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                foreach ($teams as $team) {
                    $teamLocal = Team::getByPublicId($team['id']);
                    if (!isset($teamLocal)) {
                        $teamLocal = new Team();
                    }
                    $teamLocal->idPublic = $team['id'];
                    $teamLocal->tournament = $tournamentLocal;
                    $teamLocal->name = $team['name'];
                    $teamLocal->image = $team['image'];

                    $teamLocal->save();

                    /** @var array{id:int,nickname:string,name:string|null,surname:string|null,captain:bool,sub:bool,email:string|null,phone:string|null,skill:string,birthYear:int|null,image:string|null,user:null|array{id:int,nickname:string,code:string,arena:int,email:string,stats:array{rank:int,gamesPlayed:int,arenasPlayed:int},connections:array{type:string,identifier:string}[]}} $player */
                    foreach ($team['players'] as $player) {
                        $playerLocal = Player::getByPublicId($player['id']);
                        if (!isset($playerLocal)) {
                            $playerLocal = new Player();
                        }
                        $playerLocal->idPublic = $player['id'];
                        $playerLocal->tournament = $tournamentLocal;
                        $playerLocal->team = $teamLocal;
                        $playerLocal->nickname = $player['nickname'];
                        $playerLocal->name = $player['name'];
                        $playerLocal->surname = $player['surname'];
                        $playerLocal->email = $player['email'];
                        $playerLocal->phone = $player['phone'];
                        $playerLocal->birthYear = $player['birthYear'];
                        $playerLocal->image = $player['image'];
                        $playerLocal->skill = PlayerSkill::from($player['skill']);
                        $playerLocal->captain = $player['captain'];
                        $playerLocal->sub = $player['sub'];

                        if (isset($player['user'])) {
                            $this->logger->debug(
                                'Player #' . $player['id'] . ' user - ' . json_encode(
                                    $player['user'],
                                    JSON_THROW_ON_ERROR
                                )
                            );
                            $playerLocal->user = $this->playerProvider->getPlayerObjectFromData($player['user']);
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
        foreach ($tournament->getTeams() as $team) {
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
     * @param TournamentPresetType         $type
     * @param Tournament                   $tournament
     * @param int                          $iterations
     * @param array<string,string|numeric> $args
     *
     * @return TournamentGenerator
     * @throws ValidationException
     */
    public function createTournamentFromPreset(TournamentPresetType $type, Tournament $tournament, int $iterations = 1, array $args = []): TournamentGenerator {
        $tournamentRozlos = new TournamentGenerator();
        foreach ($tournament->getTeams() as $team) {
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
                $half = (int)floor(count($tournament->getTeams()) / 4);
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
                $half = (int)floor(count($tournament->getTeams()) / 4);
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

        if ($type !== TournamentPresetType::BASE_ROUND_AND_BARRAGE && $type !== TournamentPresetType::TWO_BASE_ROUND_AND_BARRAGE) {
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
    private function prepareGamesBarrage(Tournament $tournament, TournamentGenerator $tournamentRozlos, int $baseGameCount = 3, int $maxBarrageRounds = 4): void {
        $teams = $tournamentRozlos->getTeams();
        shuffle($teams);

        // Initialize team counters
        /** @var GeneratorTeam[] $teamIds */
        $teamIds = [];
        /** @var array<int|string,int> $teamGames Team game counter where game count < baseGameCount */
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

        $baseRound = $tournamentRozlos->round(lang('Základní skupina', context: 'tournament'));
        $baseGroup = $baseRound->group('A');
        $baseGroup->setInGame($tournament->teamsInGame);
        $baseGroup->addTeam(...$teams);

        // Generate games where each team plays exactly baseGameCount games.
        // The algorithm should try to generate fair games, where each player plays the same opponent ideally only once.
        // It should probably include some kind of rollback functionality if it finds an impossible game.
        $this->generateBarrageBaseGroup(
            $teamGames,
            $teamIds,
            $tournament,
            $teamGamesWithTeams,
            $baseGroup,
            $baseGameCount
        );

        // Sort the games to minimize one team playing multiple games back to back
        $baseGroup->orderGames();

        // Generate the final barrage turns
        $roundNames = [
            lang('Finále'),
            lang('Semifinále'),
            lang('Čtvrtfinále'),
            lang('Osmifinále'),
        ];
        $otherRoundName = lang('Předkolo');
        $alphabet = range('A', 'Z');
        $roundCounter = 1;
        $barrageRounds = min(
            floor(1 + ((count($teams) - $tournament->teamsInGame) / ($tournament->teamsInGame - 1))),
            $maxBarrageRounds
        );
        bdump($barrageRounds);

        // First round should have max number of teams already progressed.
        // All next rounds should have max-1 teams progressed.
        // 1 team progresses from each round to the next.
        $round = $tournamentRozlos->round($roundNames[$barrageRounds - 1] ?? $otherRoundName);
        $group = $round->group($alphabet[$roundCounter])->setInGame($tournament->teamsInGame);
        $progression = $baseGroup->progression(
            $group,
            ($barrageRounds - 1) * ($tournament->teamsInGame - 1),
            $tournament->teamsInGame
        );
        $groupTeams = [];
        for ($t = 0; $t < $tournament->teamsInGame; $t++) {
            $groupTeams[] = $team = new BlankTeam($alphabet[$roundCounter] . $t, $teams[$t], $baseGroup, $progression);
            $group->addTeam($team);
        }
        $group->game($groupTeams);
        for ($i = 1; $i < $barrageRounds; $i++) {
            $newRound = $tournamentRozlos->round($roundNames[$barrageRounds - 1 - $i] ?? $otherRoundName);
            $newGroup = $newRound->group($alphabet[$roundCounter + $i])->setInGame($tournament->teamsInGame);
            $progression = $baseGroup->progression(
                $newGroup,
                // teams in the first round + for each previous round
                ($barrageRounds - 1 - $i) * ($tournament->teamsInGame - 1),
                $tournament->teamsInGame - 1
            );
            // Progress team from the previous round
            $progression2 = $group->progression($newGroup, 0, 1);
            $newGroupTeams = [];
            for ($t = 0; $t < ($tournament->teamsInGame - 1); $t++) {
                $newGroupTeams[] = $team = new BlankTeam(
                    $alphabet[$roundCounter] . $t,
                    $teams[$t],
                    $baseGroup,
                    $progression
                );
                $newGroup->addTeam($team);
            }
            $newGroupTeams[] = $team = new BlankTeam(
                $alphabet[$roundCounter] . count($newGroupTeams),
                $teams[count($newGroupTeams)],
                $group,
                $progression2
            );
            $newGroup->addTeam($team);
            $newGroup->game($newGroupTeams);
            $group = $newGroup;
        }
    }

    /**
     * Generates games for the barrage base group.
     *
     * This method generates games for the base group, where each team plays exactly $baseGameCount games.
     * The algorithm aims to create fair games, where each player ideally plays the same opponent only once.
     * If it encounters an impossible game, it should include some form of rollback functionality.
     *
     * @param array<int|string,int>                   $teamGames          An array of team games counters.
     * @param array<int|string,GeneratorTeam>         $teamIds            An array of team objects, indexed by their IDs.
     * @param Tournament                              $tournament         The tournament object.
     * @param array<int|string,array<int|string,int>> $teamGamesWithTeams An array representing the game matrix between teams.
     *                                                                    Each element is another array indexed by the team IDs, which holds the number of games played between each pair of teams.
     * @param GeneratorGroup                          $baseGroup          The base group object.
     * @param int                                     $baseGameCount      The number of games each team should play.
     *
     * @return void
     * @throws Exception
     *
     * @post The games will be generated and added to the $baseGroup object.
     */
    private function generateBarrageBaseGroup(array $teamGames, array $teamIds, Tournament $tournament, array $teamGamesWithTeams, GeneratorGroup $baseGroup, int $baseGameCount): void {
        // Generate games where each team plays exactly baseGameCount games.
        // The algorithm should try to generate fair games, where each player plays the same opponent ideally only once.
        // It should probably include some kind of rollback functionality if it finds an impossible game.
        $it = 0;
        $allGames = [];
        $allTeam1 = [];
        while (count($teamGames) > 0 && $it < 50) {
            $notEnoughTeams = false;
            $it++;

            // Step 1 - choose the team with the most games
            $maxTeams = [];
            $maxGames = max($teamGames);
            foreach ($teamGames as $id => $games) {
                if ($games === $maxGames) {
                    $maxTeams[] = $id;
                }
            }
            $team1Id = $maxTeams[array_rand($maxTeams)];
            $team1 = $teamIds[$team1Id];
            $allTeam1[] = $team1Id;

            // Step 2 - Choose other teams that the team1 played the least games with
            /** @var array<string|int> $otherTeamIds */
            $otherTeamIds = [];
            for ($i = 1; $i < $tournament->teamsInGame; $i++) {
                // Filtered teams
                $otherTeamsSearch = array_filter(
                    $teamGamesWithTeams[$team1Id],
                    static fn(int|string $id) => $id !== $team1Id && !in_array(
                        $id,
                        $otherTeamIds,
                        true
                    ) && isset($teamGames[$id]),
                    ARRAY_FILTER_USE_KEY
                );
                bdump($otherTeamsSearch);
                if (count($otherTeamsSearch) === 0) {
                    bdump('ERROR');
                    $notEnoughTeams = true;
                    break; // TODO: Handle error
                }
                $minTeams = [];
                $minGames = min($otherTeamsSearch);
                foreach ($otherTeamsSearch as $id => $games) {
                    if ($games === $minGames) {
                        $minTeams[] = $id;
                    }
                }
                $otherTeamIds[] = $minTeams[array_rand($minTeams)];
            }
            if ($notEnoughTeams) {
                continue;
            }
            $otherTeams = array_map(static fn(string|int $id) => $teamIds[$id], $otherTeamIds);

            // Step 3 - Create a game and increment all counters
            $allGames[] = $baseGroup->game([$team1, ...$otherTeams]);
            $teamGames[$team1Id]++;
            foreach ($otherTeamIds as $id) {
                $teamGames[$id]++;
                // The matrix should be reflexive.
                $teamGamesWithTeams[$team1Id][$id]++;
                $teamGamesWithTeams[$id][$team1Id]++;
                foreach ($otherTeamIds as $id2) {
                    if ($id === $id2) {
                        continue;
                    }
                    $teamGamesWithTeams[$id][$id2]++;
                    // Pair id2, id will be incremented on another iteration of the parent foreach.
                }
            }

            // Step 4 - Remove teams with exactly baseGameCount games
            foreach ($teamGames as $id => $games) {
                if ($games === $baseGameCount) {
                    unset($teamGames[$id]);
                }
            }
            bdump($teamGames);
        }
        bdump($teamGamesWithTeams);
    }

    private function prepareTwoGroupsGamesBarrage(Tournament $tournament, TournamentGenerator $tournamentRozlos, int $baseGameCount = 3, int $maxBarrageRounds = 4): void {
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
            // Initialize team counters
            /** @var GeneratorTeam[] $teamIds */
            $teamIds = [];
            /** @var array<int|string,int> $teamGames Team game counter where game count < baseGameCount */
            $teamGames = [];
            /** @var array<int|string,array<int|string,int>> $teamGamesWithTeams */
            $teamGamesWithTeams = [];
            foreach ($baseGroup->getTeams() as $team) {
                $teamIds[$team->getId()] = $team;
                $teamGames[$team->getId()] = 0;
                $teamGamesWithTeams[$team->getId()] = [];
                foreach ($teams as $team2) {
                    $teamGamesWithTeams[$team->getId()][$team2->getId()] = 0;
                }
            }

            bdump($baseGroup->getName());
            bdump($teamGames);

            $this->generateBarrageBaseGroup(
                $teamGames,
                $teamIds,
                $tournament,
                $teamGamesWithTeams,
                $baseGroup,
                $baseGameCount
            );

        }

        // Sort the games to minimize one team playing multiple games back to back
        $baseGroup1->orderGames();
        $baseGroup2->orderGames();

        // Generate playoff round
        $barrageRounds = min(
            floor(1 + ((count($teams) - $tournament->teamsInGame) / ($tournament->teamsInGame - 1))),
            $maxBarrageRounds
        );
        bdump($barrageRounds);

        $playoff = $tournamentRozlos->round(lang('Play-off'));
        $playoffGroup1 = $playoff->group('C')->setInGame($tournament->teamsInGame);
        $playoffGroup2 = $playoff->group('D')->setInGame($tournament->teamsInGame);
        // The last players that were not in the
        $progression1 = $baseGroup1->progression(
            $playoffGroup1,
            $barrageRounds - 1,
            $tournament->teamsInGame,
        );
        $progression2 = $baseGroup2->progression(
            $playoffGroup2,
            $barrageRounds - 1,
            $tournament->teamsInGame,
        );
        $group1Teams = [];
        $group2Teams = [];
        for ($t = 0; $t < $tournament->teamsInGame; $t++) {
            $group1Teams[] = $team = new BlankTeam('C' . $t, $teams[$t], $baseGroup1, $progression1);
            $playoffGroup1->addTeam($team);
            $group2Teams[] = $team = new BlankTeam('D' . $t, $teams[$t], $baseGroup2, $progression2);
            $playoffGroup2->addTeam($team);
        }
        $playoffGroup1->game($group1Teams);
        $playoffGroup2->game($group2Teams);

        // Generate the final barrage turns
        $roundNames = [
            lang('Finále'),
            lang('Semifinále'),
            lang('Čtvrtfinále'),
            lang('Osmifinále'),
        ];
        $otherRoundName = lang('Předkolo');
        $alphabet = range('A', 'Z');
        $roundCounter = 3;

        // First round should have max number of teams already progressed from the play-off.
        // All next rounds should have max-1 teams progressed.
        // 1 team progresses from each round to the next.
        $groupTeams = [];
        $round = $tournamentRozlos->round($roundNames[$barrageRounds - 1] ?? $otherRoundName);
        $group = $round->group($alphabet[$roundCounter])->setInGame($tournament->teamsInGame);
        $progression1 = $playoffGroup1->progression(
            $group,
            0,
            1
        );
        $groupTeams[] = $team = new BlankTeam($alphabet[$roundCounter] . 0, $teams[0], $playoffGroup1, $progression1);
        $group->addTeam($team);
        $progression2 = $playoffGroup2->progression(
            $group,
            0,
            1
        );
        $groupTeams[] = $team = new BlankTeam($alphabet[$roundCounter] . 1, $teams[1], $playoffGroup2, $progression2);
        $group->addTeam($team);
        $progression3 = $group->multiProgression(
            [$playoffGroup1, $playoffGroup2],
            1,
            1,
            1
        );
        $groupTeams[] = $team = new BlankTeam($alphabet[$roundCounter] . 2, $teams[2], $playoffGroup1, $progression3);
        $group->addTeam($team);
        $group->game($groupTeams);
        $pointsIncrement = 20;
        $progression1->setPoints($pointsIncrement);
        $progression2->setPoints($pointsIncrement);
        $progression3->setPoints($pointsIncrement);

        for ($i = 1; $i < $barrageRounds; $i++) {
            $newRound = $tournamentRozlos->round($roundNames[$barrageRounds - 1 - $i] ?? $otherRoundName);
            $newGroup = $newRound->group($alphabet[$roundCounter + $i + 2])->setInGame($tournament->teamsInGame);
            $newGroupTeams = [];

            $progression1 = $baseGroup1->progression(
                $newGroup,
                $barrageRounds - $i - 1,
                1
            );
            $newGroupTeams[] = $team = new BlankTeam(
                $alphabet[$roundCounter] . 0,
                $teams[0],
                $baseGroup1,
                $progression1
            );
            $newGroup->addTeam($team);
            $progression2 = $baseGroup2->progression(
                $newGroup,
                $barrageRounds - $i - 1,
                1
            );
            $newGroupTeams[] = $team = new BlankTeam(
                $alphabet[$roundCounter] . 1,
                $teams[1],
                $baseGroup2,
                $progression2
            );
            $newGroup->addTeam($team);
            // Progress team from the previous round
            $progression3 = $group->progression($newGroup, 0, 1);
            $newGroupTeams[] = $team = new BlankTeam(
                $alphabet[$roundCounter] . 2,
                $teams[2],
                $group,
                $progression3
            );
            $newGroup->addTeam($team);

            $points = $pointsIncrement * ($barrageRounds + 1);
            $progression1->setPoints($points);
            $progression2->setPoints($points);
            $progression3->setPoints($pointsIncrement);

            $newGroup->game($newGroupTeams);
            $group = $newGroup;
        }
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
            $progressions = array_merge(
                $group->getProgressionsFrom(),
                $group->getMultiProgressionsFrom()
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

                // Find progressed teams
                /** @var Team[] $progressedTeams */
                $progressedTeams = [];
                $keys = $progression->getKeys();
                foreach ($progressionRozlos->getProgressedTeams() as $team) {
                    $key = array_shift($keys);
                    $progressedTeams[$key] = Team::get($team->getId());
                    if (empty($keys)) {
                        break;
                    }
                }

                // Update the games
                $to = $progression->to;
                foreach ($to->getGames() as $game) {
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

        $this->cache->clean(
            [
                $this->cache::Tags => ['tournament/' . $tournament->id . '/group/teams'],
            ]
        );

        return $progressed;
    }

    public function reconstructTournament(Tournament $tournament): TournamentGenerator {
        $tournamentGenerator = new TournamentGenerator($tournament->name);

        $tournamentGenerator
            ->setPlay($tournament->gameLength)
            ->setGameWait($tournament->gamePause);
        $teams = [];
        foreach ($tournament->getTeams() as $team) {
            $teams[$team->id] = $tournamentGenerator->team($team->name, $team->id);
        }

        bdump($teams);

        $rounds = [];
        $groups = [];
        $games = [];
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

            foreach ($group->getGames() as $game) {
                if (count(array_filter($game->teams, static fn($team) => isset($team->team))) < 2) {
                    continue; // Skip planned games without any teams
                }
                $gameTeams = [];
                $results = [];
                $full = true;
                foreach ($game->teams as $team) {
                    if (!isset($teams[$team->team->id])) {
                        $full = false;
                        continue;
                    }
                    $gameTeams[] = $teams[$team->team->id];
                    $results[$team->team->id] = $team->score;
                }
                $groups[$group->id]->addTeam(...$gameTeams);
                if (!$full) {
                    continue;
                }
                $games[$game->id] = $groups[$group->id]->game($gameTeams)->setId($game->id);
                if (isset($game->code)) {
                    $games[$game->id]->setResults($results);
                }
            }

            foreach ($group->getTeams() as $team) {
                if (!isset($teams[$team->id]->groupResults[$group->id])) {
                    continue;
                }
                $teams[$team->id]->groupResults[$group->id]['points'] = $team->getPointsForGroup($group);
                $teams[$team->id]->groupResults[$group->id]['score'] = $team->getScoreForGroup($group);
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
            foreach ($progression->to->getGames() as $game) {
                foreach ($game->teams as $team) {
                    if (!isset($team->team) || !in_array($team->key, $keys, true)) {
                        continue;
                    }
                    unset($keys[array_search($team->key, $keys)]);
                    if (empty($keys)) {
                        break;
                    }
                }
                if (empty($keys)) {
                    break;
                }
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
            foreach ($progression->to->getGames() as $game) {
                foreach ($game->teams as $team) {
                    if (!isset($team->team) || !in_array($team->key, $keys, true)) {
                        continue;
                    }
                    unset($keys[array_search($team->key, $keys)]);
                    if (empty($keys)) {
                        break;
                    }
                }
                if (empty($keys)) {
                    break;
                }
            }
            $progressionRozlos->setProgressed(empty($keys));
            $progression->progression = $progressionRozlos;
        }

        return $tournamentGenerator;
    }

    public function recalcTeamPoints(Tournament $tournament): void {
        $teams = $tournament->getTeams();
        $progressions = $tournament->getProgressions();
        /** @var array<int,int> $points Sum points for games for each team */
        $points = DB::select(GameTeam::TABLE, 'id_team, SUM(points) as points')->groupBy('id_team')->fetchPairs(
            'id_team',
            'points',
            false
        );
        foreach ($teams as $team) {
            $team->points = $points[$team->id] ?? 0;

            // Check progressions
            $keys = $team->getGroupKeys();
            foreach ($progressions as $progression) {
                $progressionKeys = $progression->getKeys();
                if (in_array($keys[$progression->to->id] ?? null, $progressionKeys, true)) {
                    $team->points += $progression->points;
                }
            }

            // Save changes
            $team->save();
        }
    }
}
