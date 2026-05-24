<?php

namespace LAC\Modules\Tournament\Controllers;

use App\Core\App;
use App\Core\Info;
use App\GameModels\Game\Team;
use App\GameModels\Vest;
use App\Gate\Models\GateScreenModel;
use App\Gate\Models\MusicGroupDto;
use App\Models\Auth\Player as LigaPlayer;
use App\Models\MusicMode;
use App\Models\Playlist;
use App\Models\System;
use App\Tools\GameLoading\GameLoader;
use App\Tools\GameLoading\LasermaxxGameLoader;
use DateInterval;
use DateTimeImmutable;
use LAC\Modules\Tournament\Dto\FairTeamPlayer;
use LAC\Modules\Tournament\Models\Game;
use LAC\Modules\Tournament\Models\GameTeam;
use LAC\Modules\Tournament\Models\Group;
use LAC\Modules\Tournament\Models\MultiProgression;
use LAC\Modules\Tournament\Models\Player;
use LAC\Modules\Tournament\Models\Progression;
use LAC\Modules\Tournament\Models\Team as TournamentTeam;
use LAC\Modules\Tournament\Models\Tournament;
use LAC\Modules\Tournament\Models\TournamentPresetType;
use LAC\Modules\Tournament\Services\FairTeams;
use LAC\Modules\Tournament\Services\RandomTeamNames;
use LAC\Modules\Tournament\Services\TournamentProvider;
use LAC\Modules\Tournament\Templates\TournamentPlayTemplate;
use Lsr\Caching\Cache;
use Lsr\Core\Controllers\Controller;
use Lsr\Core\Requests\Dto\SuccessResponse;
use Lsr\Core\Requests\Request;
use Lsr\Core\Templating\Latte;
use Lsr\Db\DB;
use Lsr\Interfaces\SessionInterface;
use Lsr\Lg\Results\Enums\GameModeType;
use Lsr\Logging\Logger;
use Lsr\ObjectValidation\Exceptions\ValidationException;
use Lsr\Orm\Exceptions\ModelNotFoundException;
use Lsr\Orm\ModelCollection;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;
use TournamentGenerator\BlankTeam;
use TournamentGenerator\MultiProgression as MultiProgressionRozlos;
use TournamentGenerator\Progression as ProgressionRozlos;

/**
 * @phpstan-import-type GameData from LasermaxxGameLoader
 */
class TournamentController extends Controller
{
    public const array EVO5_TEAM_COLORS = [0 => 1, 1 => 2, 2 => 0, 3 => 3, 4 => 4, 5 => 5];

    private Logger $logger;

    public function __construct(
        Latte                               $latte,
        private readonly TournamentProvider $tournamentProvider,
        private readonly GameLoader         $gameLoader,
        private readonly SessionInterface   $session,
        private readonly RandomTeamNames    $teamNames,
    )
    {
        $this->logger = new Logger(LOG_DIR . 'controllers/', 'tournament');
    }

    public function index(): ResponseInterface
    {
        $this->params['tournaments'] = Tournament::query()->where('[active] = 1 AND DATE([start]) >= CURDATE()')->get();
        return $this->view('../modules/Tournament/templates/index');
    }

    public function oldTournaments(): ResponseInterface
    {
        $this->params['tournaments'] = Tournament::query()
            ->where('[active] = 1 AND DATE([start]) < CURDATE()')
            ->orderBy(
                'start'
            )
            ->desc()
            ->get();
        return $this->view('../modules/Tournament/templates/old');
    }

    public function show(Tournament $tournament): ResponseInterface
    {
        $this->params['tournament'] = $tournament;
        return $this->view('../modules/Tournament/templates/show');
    }

    public function rozlos(Tournament $tournament): ResponseInterface
    {
        $this->params['tournament'] = $tournament;
        $this->params['groups'] = $tournament->groups;
        $this->params['teams'] = $tournament->teams;
        $this->params['rozlosValues'] = $this->getSavedRozlosValues($tournament);
        $this->params['games'] = $tournament->getGames();
        $this->params['addGameStart'] = $this->getDefaultNewGameStart($tournament);
        $this->params['mermaidBracket'] = $this->tournamentProvider->buildMermaidBracket($tournament);
        $this->params['bracketLegend'] = $this->tournamentProvider->getBracketStatusLegend();
        $this->params['progressionControls'] = $this->buildProgressionControls($tournament);
        $this->params['addJs'] = ['modules/tournament/rozlos.js', 'modules/tournament/bracket.js'];
        return $this->view('../modules/Tournament/templates/rozlos');
    }

    private function getDefaultNewGameStart(Tournament $tournament): DateTimeImmutable
    {
        $games = $tournament->getGames();
        if (empty($games)) {
            return DateTimeImmutable::createFromInterface($tournament->start);
        }

        usort(
            $games,
            static fn(Game $a, Game $b) => $a->start <=> $b->start ?: $a->id <=> $b->id
        );

        return DateTimeImmutable::createFromInterface(end($games)->start)
            ->add(new DateInterval('PT' . ($tournament->gameLength + $tournament->gamePause) . 'M'));
    }

    /**
     * @return array<int,array{
     *     type:string,
     *     id:int,
     *     from:string,
     *     to:string,
     *     rule:string,
     *     points:int,
     *     locked:bool,
     *     slots:array<int,array{key:int,label:string,team:?TournamentTeam,locked:bool}>
     * }>
     */
    private function buildProgressionControls(Tournament $tournament): array
    {
        $controls = [];
        foreach ($tournament->getProgressions() as $progression) {
            $controls[] = $this->buildProgressionControl(
                'progression',
                $progression->id,
                $progression->from?->name ?? lang('Postup', domain: 'tournament'),
                $progression->to,
                $this->getProgressionRuleLabel($progression),
                $progression->points,
                $progression->getKeys()
            );
        }
        foreach ($tournament->getMultiProgressions() as $progression) {
            $from = array_map(
                static fn(Group $group) => $group->name,
                iterator_to_array($progression->from)
            );
            $controls[] = $this->buildProgressionControl(
                'multi',
                $progression->id,
                implode(', ', $from),
                $progression->to,
                $this->getMultiProgressionRuleLabel($progression),
                $progression->points,
                $progression->getKeys()
            );
        }

        return $controls;
    }

    /**
     * @param int[] $keys
     *
     * @return array{
     *     type:string,
     *     id:int,
     *     from:string,
     *     to:string,
     *     rule:string,
     *     points:int,
     *     locked:bool,
     *     slots:array<int,array{key:int,label:string,team:?TournamentTeam,locked:bool}>
     * }
     */
    private function buildProgressionControl(
        string $type,
        int    $id,
        string $from,
        Group  $to,
        string $rule,
        int    $points,
        array  $keys
    ): array
    {
        $slots = [];
        foreach ($keys as $key) {
            $slots[$key] = [
                'key' => $key,
                'label' => sprintf(lang('Slot %d', domain: 'tournament'), $key),
                'team' => null,
                'locked' => false,
            ];
        }

        foreach ($to->games as $game) {
            foreach ($game->teams as $gameTeam) {
                if (!isset($slots[$gameTeam->key])) {
                    continue;
                }
                $slots[$gameTeam->key]['label'] = $gameTeam->name;
                if (isset($gameTeam->team)) {
                    $slots[$gameTeam->key]['team'] = $gameTeam->team;
                }
                if (
                    $game->hasScores()
                ) {
                    $slots[$gameTeam->key]['locked'] = true;
                }
            }
        }

        $locked = false;
        foreach ($slots as $slot) {
            if ($slot['locked']) {
                $locked = true;
                break;
            }
        }

        return [
            'type' => $type,
            'id' => $id,
            'from' => $from,
            'to' => $to->name,
            'rule' => $rule,
            'points' => $points,
            'locked' => $locked,
            'slots' => $slots,
        ];
    }

    private function getProgressionRuleLabel(Progression $progression): string
    {
        $length = $progression->length ?? count($progression->getKeys());
        return sprintf(
            lang('Postoupí %d %s od %d. místa', domain: 'tournament'),
            $length,
            $length === 1 ? lang('tým', domain: 'tournament') : lang('týmy', domain: 'tournament'),
            ($progression->start ?? 0) + 1
        );
    }

    private function getMultiProgressionRuleLabel(MultiProgression $progression): string
    {
        $sourceLength = $progression->length ?? 1;
        $totalLength = $progression->totalLength ?? count($progression->getKeys());
        if ($sourceLength === 1) {
            return sprintf(
                lang('Postoupí nejlepší %d %s z %d. míst', domain: 'tournament'),
                $totalLength,
                $totalLength === 1 ? lang('tým', domain: 'tournament') : lang('týmy', domain: 'tournament'),
                ($progression->start ?? 0) + 1
            );
        }

        return sprintf(
            lang('Postoupí nejlepší %d %s z týmů od %d. místa', domain: 'tournament'),
            $totalLength,
            $totalLength === 1 ? lang('tým', domain: 'tournament') : lang('týmy', domain: 'tournament'),
            ($progression->start ?? 0) + 1
        );
    }

    public function ops(Tournament $tournament, Request $request): ResponseInterface
    {
        $this->params['tournament'] = $tournament;
        $this->params['games'] = $tournament->getGames();
        $this->params['unpairedGames'] = $this->tournamentProvider->getUnpairedResultGames($tournament);
        $this->params['selectedResultCode'] = (string)$request->getGet('result', '');
        $this->params['selectedGameId'] = (int)$request->getGet('tournament_game', 0);
        $this->params['selectedResultGame'] = !empty($this->params['selectedResultCode'])
            ? $this->tournamentProvider->getResultGameByCode($this->params['selectedResultCode'])
            : null;
        $this->params['selectedTournamentGame'] = null;
        if ($this->params['selectedGameId'] > 0) {
            try {
                $selectedTournamentGame = Game::get($this->params['selectedGameId']);
                if ($selectedTournamentGame->tournament->id === $tournament->id) {
                    $this->params['selectedTournamentGame'] = $selectedTournamentGame;
                }
            } catch (ModelNotFoundException) {
            }
        }
        $this->params['bracketLegend'] = $this->tournamentProvider->getBracketStatusLegend();
        return $this->view('../modules/Tournament/templates/ops');
    }

    public function opsPairImportedGame(Tournament $tournament, Request $request): ResponseInterface
    {
        $code = (string)$request->getPost('result_code', '');
        $targetGame = $request->getPost('tournament_game');
        if (empty($code) || !is_numeric($targetGame)) {
            $request->addPassError(lang('Vyberte importovanou i turnajovou hru', domain: 'tournament'));
            return $this->app->redirect(['tournament', $tournament->id, 'ops'], $request);
        }

        try {
            $result = $this->tournamentProvider->pairImportedGame(
                $tournament,
                $code,
                (int)$targetGame,
                is_array($request->getPost('team_map')) ? $request->getPost('team_map') : [],
                is_array($request->getPost('score')) ? $request->getPost('score') : [],
                is_array($request->getPost('position')) ? $request->getPost('position') : [],
                is_array($request->getPost('player_map')) ? $request->getPost('player_map') : [],
                $request->getPost('overwrite') !== null
            );
            $request->passNotices[] = [
                'type' => 'success',
                'content' => lang(
                    'Hra byla spárována. Týmů: %d, hráčů: %d',
                    domain: 'tournament',
                    format: [$result['teams'], $result['players']]
                ),
            ];
        } catch (Throwable $e) {
            $this->logger->exception($e);
            $request->addPassError($e->getMessage());
        }

        return $this->app->redirect(['tournament', $tournament->id, 'ops'], $request);
    }

    public function opsRecover(Tournament $tournament, Request $request): ResponseInterface
    {
        try {
            $result = $this->tournamentProvider->recoverTournament($tournament, $request->getPost('sync') !== null);
            $request->passNotices[] = [
                'type' => 'success',
                'content' => lang(
                    'Obnova dokončena. Postoupeno skupin: %d',
                    domain: 'tournament',
                    format: [$result['progressed']]
                ),
            ];
        } catch (Throwable $e) {
            $this->logger->exception($e);
            $request->addPassError($e->getMessage());
        }
        return $this->app->redirect(['tournament', $tournament->id, 'ops'], $request);
    }

    public function rozlosProcess(Tournament $tournament, Request $request): ResponseInterface
    {
        $this->saveRozlosValues($tournament, $request);

        $teams = $this->getActiveTournamentTeams($tournament, $request);
        if (count($teams) < $tournament->teamsInGame) {
            $request->addPassError(
                lang('Pro rozlosování není vybrán dostatečný počet týmů.', domain: 'tournament')
            );
            return $this->bracketActionResponse($tournament, $request);
        }
        $tournament->teams = $teams;
        $type = TournamentPresetType::tryFrom($request->getPost('tournament-type', ''));
        if ($type === null) {
            $request->addPassError(lang('Neplatný typ turnaje'));
            return $this->bracketActionResponse($tournament, $request);
        }
        $tournament->gameLength = (int)$request->getPost('game-length', 15);
        $tournament->gamePause = (int)$request->getPost('game-pause', 5);
        $tournamentStart = (int)$request->getPost('tournament-start', 30);
        $iterations = (int)$request->getPost('game-repeat', 1);
        $args = $request->getPost('args', []);

        try {
            $tournamentRozlos = $this->tournamentProvider->createTournamentFromPreset(
                $type,
                $tournament,
                $iterations,
                is_array($args) ? $args : [],
            );
        } catch (Throwable $e) {
            $this->logger->exception($e);
            $request->addPassError($e->getMessage());
            return $this->bracketActionResponse($tournament, $request);
        }

        $this->tournamentProvider->reset($tournament);

        $rounds = $tournamentRozlos->getRounds();
        /** @var Group[] $groups */
        $groups = [];
        /** @var ProgressionRozlos $progressions */
        $progressions = [];
        /** @var MultiProgression[] $multiProgressions */
        $multiProgressions = [];
        foreach ($rounds as $round) {
            foreach ($round->getGroups() as $groupRozlos) {
                $group = new Group();
                $roundName = $round->getName();
                $group->round = !empty($roundName) ? $roundName : null;
                $group->name = (!empty($roundName) ? $roundName . ' - ' : '') . $groupRozlos->getName();
                $group->tournament = $tournament;
                echo 'Inserting group - ' . $group->name . "\n";
                $group->save();
                $groups[$group->id] = $group;
                $groupRozlos->setId($group->id);
                foreach ($groupRozlos->getProgressions() as $progression) {
                    if ($progression instanceof ProgressionRozlos) {
                        $progressions[] = $progression;
                    } elseif ($progression instanceof MultiProgressionRozlos) {
                        // Generate key to prevent duplicates, because the progression is saved in multiple groups
                        $ids = array_map(
                            static fn(\TournamentGenerator\Group $g) => $g->getId(),
                            $progression->getFrom()
                        );
                        sort($ids);
                        $key = implode('-', $ids) . '->' . $group->id . ':' .
                            $progression->getStart() . ':' .
                            ($progression->getLen() ?? 'all') . ':' .
                            $progression->getTotalStart() . ':' .
                            ($progression->getTotalCount() ?? 'all') . ':' .
                            ($progression->getPoints() ?? 0);
                        $multiProgressions[$key] = $progression;
                    }
                }
            }
        }

        $start = new DateTimeImmutable(
            $tournament->start->format('Y-m-d H:i:s') . ' + ' . $tournamentStart . ' minutes'
        );
        $addInterval = new DateInterval('PT' . ($tournament->gameLength + $tournament->gamePause) . 'M');

        $groupTeamKey = [];
        foreach ($tournamentRozlos->getRounds() as $round) {
            /** @var \TournamentGenerator\Game[] $roundGames */
            $roundGames = [];
            /** @var \TournamentGenerator\Game[][] $roundGroupGames */
            $roundGroupGames = [];
            $gameCount = 0;
            foreach ($round->getGroups() as $group) {
                $games = $group->getInGame() > 2 ? $group->orderGames() : $group->getGames();
                $gameCount += count($games);
                $roundGroupGames[] = $games;
            }
            $i = 0;
            while ($i < $gameCount) {
                foreach ($roundGroupGames as $key => $games) {
                    if (count($games) === 0) {
                        continue;
                    }
                    $roundGames[] = array_shift($roundGroupGames[$key]);
                    $i++;
                }
            }

            foreach ($roundGames as $gameRozlos) {
                $game = new Game();
                $game->tournament = $tournament;
                $game->group = $groups[$gameRozlos->getGroup()->getId()];
                if (!isset($groupTeamKey[$game->group->id])) {
                    $groupTeamKey[$game->group->id] = [];
                }
                $game->start = $start;
                foreach ($gameRozlos->getTeams() as $teamRozlos) {
                    $gameTeam = new GameTeam();
                    $gameTeam->game = $game;
                    if ($teamRozlos instanceof BlankTeam) {
                        if (!isset($groupTeamKey[$game->group->id][$teamRozlos->getId()])) {
                            $groupTeamKey[$game->group->id][$teamRozlos->getId()] = count(
                                $groupTeamKey[$game->group->id]
                            );
                        }
                        $gameTeam->key = $groupTeamKey[$game->group->id][$teamRozlos->getId()];
                    } else {
                        $gameTeam->team = $teams[$teamRozlos->getId()];
                        if (!isset($groupTeamKey[$game->group->id][$gameTeam->team->id])) {
                            $groupTeamKey[$game->group->id][$gameTeam->team->id] = count(
                                $groupTeamKey[$game->group->id]
                            );
                        }
                        $gameTeam->key = $groupTeamKey[$game->group->id][$gameTeam->team->id];
                    }

                    $game->teams->push($gameTeam);
                }
                echo 'Inserting game - ' . implode(',', $game->teams->map(fn($team) => $team->key)) . "\n";
                $game->save();
                $start = $start->add($addInterval);
            }
        }

        foreach ($progressions as $progressionRozlos) {
            $progression = new Progression();
            $progression->tournament = $tournament;
            $progression->from = $groups[$progressionRozlos->getFrom()->getId()];
            $progression->to = $groups[$progressionRozlos->getTo()->getId()];
            $progression->start = $progressionRozlos->getStart();
            $progression->length = $progressionRozlos->getLen();
            $progression->filters = serialize($progressionRozlos->getFilters());
            $progression->points = $progressionRozlos->getPoints() ?? 0;

            $keys = [];
            $count = $progression->length;
            if (!isset($count) && isset($progression->start)) {
                $count = $progressionRozlos->getFrom()->getTeamContainer()->count() - $progression->start;
            }
            if ($count > 0) {
                for ($i = 0; $i < $count; $i++) {
                    if (!isset($groupTeamKey[$progression->to->id])) {
                        break;
                    }
                    $keys[] = array_shift($groupTeamKey[$progression->to->id]);
                }
            }
            $progression->setKeys($keys);

            $progression->save();
        }

        foreach ($multiProgressions as $progressionRozlos) {
            $progression = new MultiProgression();
            $progression->tournament = $tournament;
            $progression->from = new ModelCollection([]);
            foreach ($progressionRozlos->getFrom() as $from) {
                $progression->from[] = $groups[$from->getId()];
            }
            $progression->to = $groups[$progressionRozlos->getTo()->getId()];
            $progression->start = $progressionRozlos->getStart();
            $progression->length = $progressionRozlos->getLen();
            $progression->filters = serialize($progressionRozlos->getFilters());
            $progression->points = $progressionRozlos->getPoints() ?? 0;
            $progression->totalStart = $progressionRozlos->getTotalStart();
            $progression->totalLength = $progressionRozlos->getTotalCount();

            $keys = [];
            $count = $progression->totalLength ?? ($progression->length * count($progression->from));
            if ($count > 0) {
                for ($i = 0; $i < $count; $i++) {
                    $keys[] = array_shift($groupTeamKey[$progression->to->id]);
                }
            }
            $progression->setKeys($keys);

            $progression->save();
        }
        echo 'Rozlos done...' . PHP_EOL;
        $request->passNotices[] = ['type' => 'success', 'content' => lang('Vygenerováno')];
        return $this->bracketActionResponse($tournament, $request);
    }

    /**
     * @return array<int,TournamentTeam>
     */
    private function getActiveTournamentTeams(Tournament $tournament, Request $request): array
    {
        $body = $request->getParsedBody();
        $hasActiveTeamSelection = is_array($body)
            ? array_key_exists('teams_selection', $body)
            : is_object($body) && property_exists($body, 'teams_selection');
        if (!$hasActiveTeamSelection) {
            return $tournament->teams;
        }

        $activeTeamIds = $request->getPost('teams_active', []);
        if (!is_array($activeTeamIds)) {
            $activeTeamIds = [$activeTeamIds];
        }

        $activeTeamIds = array_map('intval', $activeTeamIds);
        $teams = [];
        foreach ($tournament->teams as $team) {
            if (!in_array($team->id, $activeTeamIds, true)) {
                continue;
            }
            $teams[$team->id] = $team;
        }

        return $teams;
    }

    private function bracketActionResponse(Tournament $tournament, Request $request): ResponseInterface
    {
        if ($request->isAjax()) {
            if (!empty($request->passErrors)) {
                return $this->respond(
                    [
                        'status' => 'error',
                        'error' => implode('<br>', $request->passErrors),
                        'errors' => $request->passErrors,
                        'notices' => $request->passNotices,
                    ],
                    400
                );
            }

            return $this->respond(
                [
                    'status' => 'ok',
                    'errors' => [],
                    'notices' => $request->passNotices,
                ]
            );
        }

        return $this->app->redirect(['tournament', (string)$tournament->id, 'rozlos'], $request);
    }

    /**
     * @return array{
     *     'tournament-type'?:string,
     *     'game-repeat'?:int,
     *     'game-length'?:int,
     *     'game-pause'?:int,
     *     'tournament-start'?:int,
     *     args?:array{base_game_count?:int,max_barrage_rounds?:int},
     *     teams_active?:int[]
     * }
     */
    private function getSavedRozlosValues(Tournament $tournament): array
    {
        $values = $this->session->get($this->getRozlosValuesSessionKey($tournament), []);
        return is_array($values) ? $values : [];
    }

    private function saveRozlosValues(Tournament $tournament, Request $request): void
    {
        $args = $request->getPost('args', []);
        $activeTeamIds = $request->getPost('teams_active', []);
        if (!is_array($args)) {
            $args = [];
        }
        if (!is_array($activeTeamIds)) {
            $activeTeamIds = [$activeTeamIds];
        }

        $this->session->set(
            $this->getRozlosValuesSessionKey($tournament),
            [
                'tournament-type' => (string)$request->getPost('tournament-type', ''),
                'game-repeat' => (int)$request->getPost('game-repeat', 1),
                'game-length' => (int)$request->getPost('game-length', 15),
                'game-pause' => (int)$request->getPost('game-pause', 5),
                'tournament-start' => (int)$request->getPost('tournament-start', 30),
                'args' => [
                    'base_game_count' => (int)($args['base_game_count'] ?? 3),
                    'max_barrage_rounds' => (int)($args['max_barrage_rounds'] ?? 4),
                ],
                'teams_active' => array_map('intval', $activeTeamIds),
            ]
        );
    }

    private function getRozlosValuesSessionKey(Tournament $tournament): string
    {
        return 'tournament/' . $tournament->id . '/rozlos-values';
    }

    public function rozlosClear(Tournament $tournament, Request $request): ResponseInterface
    {
        $this->tournamentProvider->reset($tournament);
        $request->passNotices[] = ['type' => 'success',
            'content' => lang('Rozlosování bylo smazáno', domain: 'tournament'),
        ];
        return $this->bracketActionResponse($tournament, $request);
    }

    public function addTournamentGame(Tournament $tournament, Request $request): ResponseInterface
    {
        try {
            $group = $this->getTournamentGroupFromRequest($tournament, $request);

            $game = new Game();
            $game->tournament = $tournament;
            $game->group = $group;
            $game->start = $this->getTournamentGameStartFromRequest($request);
            if (!$game->save()) {
                throw new RuntimeException(lang('Hru se nepodařilo uložit.', domain: 'tournament'));
            }

            $this->replaceTournamentGameTeams($tournament, $game, $group, $request, false);
            $request->passNotices[] = [
                'type' => 'success',
                'content' => lang('Hra byla přidána.', domain: 'tournament'),
            ];
        } catch (Throwable $e) {
            $this->logger->exception($e);
            $request->addPassError($e->getMessage());
        }

        return $this->app->redirect(['tournament', (string)$tournament->id, 'rozlos'], $request);
    }

    private function getTournamentGroupFromRequest(Tournament $tournament, Request $request): Group
    {
        $groupId = $request->getPost('group');
        if (!is_numeric($groupId)) {
            throw new RuntimeException(lang('Vyberte skupinu.', domain: 'tournament'));
        }

        $group = Group::get((int)$groupId);
        if ($group->tournament->id !== $tournament->id) {
            throw new RuntimeException(lang('Skupina nepatří do tohoto turnaje.', domain: 'tournament'));
        }

        return $group;
    }

    private function getTournamentGameStartFromRequest(Request $request): DateTimeImmutable
    {
        $start = $request->getPost('start', '');
        if (!is_string($start)) {
            throw new RuntimeException(lang('Začátek hry musí být platné datum a čas.', domain: 'tournament'));
        }
        if ($start === '' || strtotime(str_replace('T', ' ', $start)) === false) {
            throw new RuntimeException(lang('Začátek hry musí být platné datum a čas.', domain: 'tournament'));
        }

        return new DateTimeImmutable(str_replace('T', ' ', $start));
    }

    private function replaceTournamentGameTeams(
        Tournament $tournament,
        Game       $game,
        Group      $group,
        Request    $request,
        bool       $keepExistingKeys
    ): void
    {
        $slots = $request->getPost('teams', []);
        if (!is_array($slots)) {
            $slots = [];
        }
        if (!isset($game->id, $group->id)) {
            throw new RuntimeException(lang('Hru se nepodařilo uložit.', domain: 'tournament'));
        }
        /** @var array<int|string,array{key?:int|string,team?:int|string|null}|int|string|null> $slots */

        $existingKeys = [];
        foreach ($game->teams as $slot) {
            $existingKeys[] = $slot->key;
        }

        $selectedTeamIds = [];
        $rows = [];
        $nextKey = $this->getNextTournamentGameTeamKey($group);
        for ($i = 0; $i < $tournament->teamsInGame; $i++) {
            $slot = $slots[$i] ?? [];
            if (!is_array($slot)) {
                $slot = ['team' => $slot];
            }

            $teamId = $slot['team'] ?? null;
            $team = null;
            if (is_numeric($teamId)) {
                $team = TournamentTeam::get((int)$teamId);
                if ($team->tournament->id !== $tournament->id) {
                    throw new RuntimeException(lang('Tým nepatří do tohoto turnaje.', domain: 'tournament'));
                }
                if (in_array($team->id, $selectedTeamIds, true)) {
                    throw new RuntimeException(lang('Stejný tým nemůže být ve hře vícekrát.', domain: 'tournament'));
                }
                $selectedTeamIds[] = $team->id;
            }

            if ($keepExistingKeys && isset($slot['key']) && is_numeric($slot['key'])) {
                $key = (int)$slot['key'];
            } elseif ($keepExistingKeys && isset($existingKeys[$i])) {
                $key = $existingKeys[$i];
            } elseif ($team !== null && isset($team->groupKeys[$group->id])) {
                $key = $team->groupKeys[$group->id];
            } else {
                $key = $nextKey++;
            }

            $rows[] = [
                'id_game' => $game->id,
                'key' => $key,
                'id_team' => $team?->id,
            ];
        }

        DB::getConnection()->begin();
        try {
            DB::delete(GameTeam::TABLE, ['id_game = %i', $game->id]);
            foreach ($rows as $row) {
                DB::insert(GameTeam::TABLE, $row);
            }
            DB::getConnection()->commit();
        } catch (Throwable $e) {
            DB::getConnection()->rollback();
            throw $e;
        }
    }

    private function getNextTournamentGameTeamKey(Group $group): int
    {
        $max = DB::select(GameTeam::TABLE, 'MAX([key])')
            ->where(
                '[id_game] IN %sql',
                DB::select(Game::TABLE, 'id_game')
                    ->where('[id_group] = %i', $group->id)
                    ->fluent
            )
            ->fetchSingle(false);

        return is_numeric($max) ? ((int)$max) + 1 : 0;
    }

    public function updateTournamentGame(Tournament $tournament, Game $game, Request $request): ResponseInterface
    {
        if ($game->tournament->id !== $tournament->id) {
            $request->addPassError(lang('Hra nepatří do tohoto turnaje.', domain: 'tournament'));
            return $this->bracketActionResponse($tournament, $request);
        }

        try {
            $group = $this->getTournamentGroupFromRequest($tournament, $request);
            $keepExistingKeys = $game->group?->id === $group->id;
            $game->group = $group;
            $game->start = $this->getTournamentGameStartFromRequest($request);
            if (!$game->save()) {
                throw new RuntimeException(lang('Hru se nepodařilo uložit.', domain: 'tournament'));
            }

            if ($game->hasScores()) {
                $request->passNotices[] = [
                    'type' => 'warning',
                    'content' => lang(
                        'Hra má uložené výsledky, proto byly upraveny pouze čas a skupina.',
                        domain: 'tournament'
                    ),
                ];
            } else {
                $this->replaceTournamentGameTeams($tournament, $game, $group, $request, $keepExistingKeys);
                $request->passNotices[] = [
                    'type' => 'success',
                    'content' => lang('Hra byla uložena.', domain: 'tournament'),
                ];
            }
        } catch (Throwable $e) {
            $this->logger->exception($e);
            $request->addPassError($e->getMessage());
        }

        return $this->bracketActionResponse($tournament, $request);
    }

    public function deleteTournamentGame(Tournament $tournament, Game $game, Request $request): ResponseInterface
    {
        if ($game->tournament->id !== $tournament->id) {
            $request->addPassError(lang('Hra nepatří do tohoto turnaje.', domain: 'tournament'));
            return $this->bracketActionResponse($tournament, $request);
        }
        if ($game->hasScores()) {
            $request->addPassError(lang('Hru s uloženými výsledky nelze smazat.', domain: 'tournament'));
            return $this->bracketActionResponse($tournament, $request);
        }

        if ($game->delete()) {
            $request->passNotices[] = [
                'type' => 'success',
                'content' => lang('Hra byla smazána.', domain: 'tournament'),
            ];
        } else {
            $request->addPassError(lang('Hru se nepodařilo smazat.', domain: 'tournament'));
        }

        return $this->bracketActionResponse($tournament, $request);
    }

    public function moveTournamentGame(
        Tournament $tournament,
        Game       $game,
        Request    $request,
        string     $direction
    ): ResponseInterface
    {
        if ($game->tournament->id !== $tournament->id) {
            $request->addPassError(lang('Hra nepatří do tohoto turnaje.', domain: 'tournament'));
            return $this->bracketActionResponse($tournament, $request);
        }

        $games = $tournament->getGames();
        usort(
            $games,
            static fn(Game $a, Game $b) => $a->start <=> $b->start ?: $a->id <=> $b->id
        );

        $index = array_find_key($games, static fn(Game $item) => $item->id === $game->id);
        $targetIndex = match ($direction) {
            'up' => is_int($index) ? $index - 1 : null,
            'down' => is_int($index) ? $index + 1 : null,
            default => null,
        };

        if ($targetIndex === null || !isset($games[$targetIndex])) {
            $request->addPassError(lang('Hru nelze přesunout požadovaným směrem.', domain: 'tournament'));
            return $this->bracketActionResponse($tournament, $request);
        }

        $targetGame = $games[$targetIndex];
        $start = $game->start;
        $game->start = $targetGame->start;
        $targetGame->start = $start;

        if ($game->save() && $targetGame->save()) {
            $request->passNotices[] = [
                'type' => 'success',
                'content' => lang('Hra byla přesunuta.', domain: 'tournament'),
            ];
        } else {
            $request->addPassError(lang('Hru se nepodařilo přesunout.', domain: 'tournament'));
        }

        return $this->bracketActionResponse($tournament, $request);
    }

    public function sync(Request $request): ResponseInterface
    {
        if ($this->tournamentProvider->sync() && $this->tournamentProvider->syncUpcomingGames()) {
            $request->passNotices[] = ['type' => 'success', 'content' => lang('Synchronizováno')];
        } else {
            $request->addPassError(lang('Synchronizace se nezdařila'));
        }

        return $this->app->redirect(['tournament'], $request);
    }

    public function play(Tournament $tournament, Request $request, ?Game $game = null): ResponseInterface
    {
        $this->params = new TournamentPlayTemplate($this->params);
        $this->params->systems = System::getActive();
        $systemId = $request->getGet('system');
        if ($systemId === null) {
            $systemId = $this->session->get('active_lg_system');
        }
        if ($systemId === null) {
            $this->params->system = System::getDefault();
        } elseif (is_numeric($systemId)) {
            try {
                $this->params->system = System::get((int)$systemId);
            } catch (ModelNotFoundException) {
                $this->params->system = System::getDefault();
            }
        } else {
            $this->params->system = array_find(
                $this->params->systems,
                static fn(System $system) => $system->type->value === $systemId
            );
        }

        if ($this->params->system === null) {
            $this->params->system = first($this->params->systems);
        }
        $this->session->set('active_lg_system', $this->params->system->id);
        $this->params->tournament = $tournament;
        if (!isset($game)) {
            $game = $tournament->getPlannedGame();
        }
        if (!isset($game)) {
            $this->request->addPassError(lang('Nebyla nalezena žádná hra'));
            return $this->app->redirect(['tournament', $tournament->id], $this->request);
        }

        $this->params->game = $game;
        $this->params->upcomingGames = Game::query()
            ->where(
                '[id_tournament] = %i AND [code] IS NULL',
                $tournament->id
            )
            ->orderBy('start')
            ->limit(20)
            ->get();
        $this->params->vests = array_values(Vest::getForSystem('evo5'));
        $this->params->musicModes = MusicMode::getAll();
        $this->params->playlists = Playlist::getAll();
        foreach ($this->params->musicModes as $music) {
            if (!$music->public) {
                continue;
            }
            $group = empty($music->group) ? $music->name : $music->group;
            $this->params->musicGroups[$group] ??= new MusicGroupDto($group);
            $this->params->musicGroups[$group]->music[] = $music;
        }

        $gateActionScreens = GateScreenModel::query()->where('trigger_value IS NOT NULL')->get();
        $this->params->gateActions = [];
        foreach ($gateActionScreens as $gateActionScreen) {
            $this->params->gateActions[$gateActionScreen->triggerValue] = $gateActionScreen->triggerValue;
        }
        $this->params->teamColors = $this::EVO5_TEAM_COLORS;
        $this->params->addJs = ['modules/tournament/play.js'];
        $this->params->addCss = ['modules/tournament/play.css'];

        return $this->view('../modules/Tournament/templates/play');
    }

    public function playList(Tournament $tournament): ResponseInterface
    {
        $this->params['tournament'] = $tournament;
        $this->params['games'] = $tournament->getGames();
        return $this->view('../modules/Tournament/templates/playList');
    }

    public function playResults(Tournament $tournament, Game $game): ResponseInterface
    {
        if ($game->game === null) {
            return $this->respond(['status' => 'not yet finished']);
        }
        $this->params['tournament'] = $tournament;
        $this->params['game'] = $game;
        $this->params['upcomingGames'] = Game::query()->where(
            '[id_tournament] = %i AND [code] IS NULL',
            $tournament->id
        )->limit(20)->get();
        $this->params['musicModes'] = MusicMode::getAll();
        $this->params['teamColors'] = $this::EVO5_TEAM_COLORS;
        $view = $this->latte->viewToString('../modules/Tournament/templates/components/play', $this->params);
        return $this->respond(['status' => 'results', 'view' => $view]);
    }

    public function playProcess(Tournament $tournament, Game $game, Request $request): ResponseInterface
    {
        /** @var GameData $data */
        $data = [
            'game-mode' => 1,
            'mode' => Info::get('tournament_game_mode', '0-TEAM_Turnaj'),
            'music' => $request->getPost('music'),
            'meta' => [
                'tournament' => $tournament->id,
                'tournament_game' => $game->id,
            ],
            'player' => [],
            'team' => [],
            'use-playlist' => $request->getPost('use-playlist'),
            'playlist' => $request->getPost('playlist'),
        ];

        /** @var Player[] $playersAll */
        $playersAll = [];
        $key = 0;
        foreach ($game->teams as $team) {
            $color = $this::EVO5_TEAM_COLORS[$key];
            foreach ($team->team->players as $id => $player) {
                $playersAll[$id] = $player;
            }
            $data['meta']['t' . $color . 'tournament'] = $team->id;
            $data['team'][$color] = ['name' => $team->name];
            $key++;
        }

        /** @var array{name:string,vest:numeric-string,team:numeric-string}[] $players */
        $players = $request->getPost('player', []);
        foreach ($players as $id => $player) {
            if (empty($player['vest'])) {
                continue;
            }
            $player['name'] = trim($player['name']);
            $tournamentPlayer = $playersAll[$id];
            $data['meta']['p' . $player['vest'] . 'tournament'] = $tournamentPlayer->id;
            $data['player'][$player['vest']] = [
                'name' => $player['name'],
                'team' => $player['team'],
            ];
            if (isset($tournamentPlayer->user)) {
                $data['player'][$player['vest']]['code'] = $tournamentPlayer->user->getCode();
            }
        }

        $meta = $this->gameLoader->loadGame('evo6', $data);

        return $this->respond(
            new SuccessResponse(
                values: [
                    'mode' => $meta['mode'],
                    'music' => $meta['music'],
                    'group' => $meta['group'] ?? null,
                    'groupName' => $meta['groupName'] ?? null,
                ],
            )
        );
    }

    public function updateBonusScore(Tournament $tournament, Game $game, Request $request): ResponseInterface
    {
        /** @var array<int,int> $bonus */
        $bonus = $request->getPost('bonus', []);

        $results = $game->game;
        if (!isset($results)) {
            return $this->respond(['error' => 'Game is not finished yet.'], 400);
        }

        /** @var Team $team */
        foreach ($results->teams as $team) {
            if (!isset($team->tournamentTeam)) {
                $team->bonus = null;
                continue;
            }
            $team->setBonus($bonus[$team->tournamentTeam->id] ?? 0);
            $team->save();
        }

        $results->reorder();
        $results->save();

        return $this->respond(['success' => true]);
    }

    public function updateTournamentGameResults(Tournament $tournament, Game $game, Request $request): ResponseInterface
    {
        if ($game->tournament->id !== $tournament->id) {
            return $this->respond(['error' => lang('Hra nepatří do tohoto turnaje.', domain: 'tournament')], 400);
        }

        $teams = $request->getPost('teams', []);
        if (!is_array($teams)) {
            return $this->respond(['error' => lang('Neplatná data výsledků.', domain: 'tournament')], 400);
        }

        foreach ($game->teams as $gameTeam) {
            if (!isset($gameTeam->id, $teams[$gameTeam->id]) || !is_array($teams[$gameTeam->id])) {
                continue;
            }

            $data = $teams[$gameTeam->id];
            $gameTeam->score = $this->nullableInt($data['score'] ?? null);
            $gameTeam->points = $this->nullableInt($data['points'] ?? null);
            $gameTeam->save();
        }

        $this->tournamentProvider->recalcTeamPoints($tournament);
        $this->tournamentProvider->cleanTournamentCache($tournament);

        return $this->respond(['success' => true]);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int)$value : null;
    }

    public function resetGame(Tournament $tournament, Game $game): ResponseInterface
    {
        $results = $game->game;
        if (!isset($results)) {
            return $this->respond(['status' => 'No results']);
        }

        foreach ($game->teams as $team) {
            $team->team->points -= $team->points;
            $team->score = null;
            $team->position = null;
            $team->points = null;
            $team->save();
            $team->team->save();
        }
        $game->code = null;
        $game->save();

        /** @var Team $team */
        foreach ($results->teams as $team) {
            $team->tournamentTeam = null;
            $team->save();
        }
        /** @var \App\GameModels\Game\Player $player */
        foreach ($results->players as $player) {
            $player->tournamentPlayer = null;
            $player->save();
        }
        return $this->respond(['status' => 'ok']);
    }

    public function progress(Tournament $tournament): ResponseInterface
    {
        return $this->respond(['progressed' => $this->tournamentProvider->progress($tournament)]);
    }

    public function undoProgression(
        Tournament $tournament,
        Request    $request,
        string     $type,
        int        $progressionId
    ): ResponseInterface
    {
        $this->logger->debug(
            'Undo progression request received',
            [
                'tournament' => $tournament->id,
                'type' => $type,
                'progressionId' => $progressionId,
            ]
        );
        try {
            $changed = $this->tournamentProvider->undoProgression($tournament, $type, $progressionId);
            $this->logger->debug(
                'Undo progression request finished',
                [
                    'tournament' => $tournament->id,
                    'type' => $type,
                    'progressionId' => $progressionId,
                    'changed' => $changed,
                ]
            );
            $request->passNotices[] = [
                'type' => 'success',
                'content' => lang('Postup byl vrácen. Upraveno slotů: %d', domain: 'tournament', format: [$changed]),
            ];
        } catch (ModelNotFoundException|RuntimeException $e) {
            $this->logger->exception($e);
            $request->addPassError($e->getMessage());
        }

        return $this->bracketActionResponse($tournament, $request);
    }

    public function setProgressionTeam(
        Tournament $tournament,
        Request    $request,
        string     $type,
        int        $progressionId
    ): ResponseInterface
    {
        $key = $request->getPost('key');
        $teamId = $request->getPost('team');

        if (!is_numeric($key) || !is_numeric($teamId)) {
            $request->addPassError(lang('Vyberte slot i tým', domain: 'tournament'));
            return $this->bracketActionResponse($tournament, $request);
        }

        try {
            $team = TournamentTeam::get((int)$teamId);
            $changed = $this->tournamentProvider->setProgressionTeam(
                $tournament,
                $type,
                $progressionId,
                (int)$key,
                $team
            );
            $request->passNotices[] = [
                'type' => 'success',
                'content' => lang('Tým byl ručně zařazen. Upraveno slotů: %d', domain: 'tournament', format: [$changed]),
            ];
        } catch (ModelNotFoundException|RuntimeException $e) {
            $request->addPassError($e->getMessage());
        }

        return $this->bracketActionResponse($tournament, $request);
    }

    public function create(): ResponseInterface
    {
        $this->params['addJs'] = ['modules/tournament/create.js'];
        return $this->view('../modules/Tournament/templates/create');
    }

    public function createProcess(Request $request): ResponseInterface
    {
        /** @var array{name:string,start:string,format:string,team_size:int,teams_in_game:int} $values */
        $values = [];
        $errors = [];

        // Validate request
        $values['name'] = (string)$request->getPost('name', '');
        if (empty($values['name'])) {
            $errors['name'] = lang('Název turnaje je povinný');
        } else {
            if (strlen($values['name']) > 100) {
                $errors['name'] = lang('Název turnaje nesmí být delší, než 100 znaků');
            }
        }
        $values['start'] = (string)$request->getPost('start', date('d.m.Y H:i'));
        if (empty($values['start'])) {
            $errors['start'] = lang('Začátek turnaje je povinný');
        } else {
            if (!strtotime($values['start'])) {
                $errors['start'] = lang('Začátek turnaje musí být datum a čas');
            }
        }
        $values['format'] = (string)$request->getPost('format', 'TEAM');
        if (empty($values['format'])) {
            $errors['format'] = lang('Formát turnaje je povinný');
        } else {
            if (GameModeType::tryFrom($values['format']) === null) {
                $errors['format'] = lang('Formát turnaje není validní');
            }
        }
        $values['team_size'] = (int)$request->getPost('team_size', 5);
        if ($values['format'] === GameModeType::TEAM->value && $values['team_size'] < 1) {
            $errors['team_size'] = lang('Velikost týmu je povinná a musí být kladné číslo');
        }
        $values['teams_in_game'] = (int)$request->getPost('teams_in_game', 2);
        $validValues = [2, 3, 4];
        if (
            $values['format'] === GameModeType::TEAM->value && !in_array(
                $values['teams_in_game'],
                $validValues,
                true
            )
        ) {
            $errors['teams_in_game'] = sprintf(
                lang('Počet týmů ve hře je povinný a musí být validní hodnota (%s).'),
                implode(', ', $validValues)
            );
        }


        if (count($errors) > 0) {
            $this->params['values'] = $values;
            $this->params['errors'] = $errors;
            $this->params['addJs'] = ['modules/tournament/create.js'];
            return $this->view('../modules/Tournament/templates/create');
        }

        // Create tournament
        $tournament = new Tournament();
        $tournament->start = new DateTimeImmutable($values['start']);
        $tournament->format = GameModeType::from($values['format']);
        $tournament->name = $values['name'];
        $tournament->teamSize = $values['team_size'];
        $tournament->teamsInGame = $values['teams_in_game'];

        $tournament->points->win = (int)$request->getPost('points_win', 3);
        $tournament->points->draw = (int)$request->getPost('points_draw', 1);
        $tournament->points->loss = (int)$request->getPost('points_loss', 0);
        $tournament->points->second = (int)$request->getPost('points_second', 2);
        $tournament->points->third = (int)$request->getPost('points_third', 1);

        try {
            if ($tournament->save()) {
                return $this->app->redirect(['tournament', $tournament->id], $request);
            }
        } catch (ValidationException $e) {
            $errors[] = $e->getMessage();
        }
        $errors[] = lang('Turnaj se nepodařilo uložit.', domain: 'tournament');
        $this->params['values'] = $values;
        $this->params['errors'] = $errors;
        $this->params['addJs'] = ['modules/tournament/create.js'];
        return $this->view('../modules/Tournament/templates/create');
    }

    public function teams(Tournament $tournament, Request $request): ResponseInterface
    {
        $teams = $request->getPost('teams');
        if (isset($teams) && is_array($teams)) {
            foreach ($teams as $key => $teamData) {
                if (is_numeric($key)) {
                    $team = TournamentTeam::get((int)$key);
                } else {
                    $team = new TournamentTeam();
                    $team->tournament = $tournament;
                }
                if (!isset($team)) {
                    continue;
                }
                $team->name = $teamData['name'];
                bdump($team);
                $team->save();

                foreach ($teamData['players'] as $pKey => $playerData) {
                    if (is_numeric($pKey)) {
                        $player = Player::get((int)$pKey);
                    } else {
                        $player = new Player();
                        $player->team = $team;
                        $player->tournament = $tournament;
                    }
                    $player->name = $playerData['name'];
                    $player->surname = $playerData['surname'];
                    $player->nickname = $playerData['nickname'];
                    if (!empty($playerData['code'])) {
                        $user = LigaPlayer::getByCode($playerData['code']);
                        if (isset($user)) {
                            $player->user = $user;
                            $player->email = $user->email;
                        }
                    }
                    $player->save();
                }
            }
            /** @var Cache $cache */
            $cache = App::getServiceByType(Cache::class);
            $cache->clean(
                [
                    Cache::Tags => [
                        'tournament-' . $tournament->id . '-teams',
                        'tournament-' . $tournament->id . '-players',
                    ],
                ]
            );
            $request->passNotices[] = ['type' => 'success', 'content' => lang('Uloženo')];
            return $this->app->redirect(['tournament', $tournament->id, 'teams'], $request);
        }

        $this->params['tournament'] = $tournament;
        $this->params['addJs'] = ['modules/tournament/teams.js'];
        return $this->view('../modules/Tournament/templates/teams');
    }

    public function players(Tournament $tournament, Request $request): ResponseInterface
    {
        $players = $request->getPost('players');
        if (isset($players) && is_array($players)) {
            foreach ($players as $pKey => $playerData) {
                if (is_numeric($pKey)) {
                    $player = Player::get((int)$pKey);
                } else {
                    $player = new Player();
                    $player->team = null;
                    $player->tournament = $tournament;
                }
                $player->name = $playerData['name'];
                $player->surname = $playerData['surname'];
                $player->nickname = $playerData['nickname'];
                if (!empty($playerData['code'])) {
                    $user = LigaPlayer::getByCode($playerData['code']);
                    if (isset($user)) {
                        $player->user = $user;
                        $player->email = $user->email;
                    }
                }
                if (!empty($player->nickname)) {
                    $player->save();
                }
            }
            /** @var Cache $cache */
            $cache = App::getServiceByType(Cache::class);
            $cache->clean(
                [
                    Cache::Tags => [
                        'tournament-' . $tournament->id . '-players',
                    ],
                ]
            );
            $request->passNotices[] = ['type' => 'success', 'content' => lang('Uloženo')];
            return $this->app->redirect(['tournament', $tournament->id, 'players'], $request);
        }

        $this->params['tournament'] = $tournament;
        $this->params['addJs'] = ['modules/tournament/players.js'];
        return $this->view('../modules/Tournament/templates/players');
    }

    public function autoTeams(Tournament $tournament, Request $request): ResponseInterface
    {
        $players = $request->getPost('players');
        if (isset($players) && is_array($players)) {
            echo 'Prepare fair teams' . PHP_EOL;
            $fairPlayers = [];
            $fairUnregisteredPlayers = [];
            foreach ($players as $pKey => $playerData) {
                if (is_numeric($pKey)) {
                    $player = Player::get((int)$pKey);
                } else {
                    $player = new Player();
                    $player->team = null;
                    $player->tournament = $tournament;
                }
                $player->nickname = $playerData['nickname'];
                if (empty($player->nickname)) {
                    continue;
                }
                if (!empty($playerData['code'])) {
                    $user = LigaPlayer::getByCode($playerData['code']);
                    if (isset($user)) {
                        $player->user = $user;
                        $player->email = $user->email;
                        $fairPlayers[] = new FairTeamPlayer($player, $user->rank);
                        $player->save();
                        continue;
                    }
                }
                $player->save();
                $fairUnregisteredPlayers[] = new FairTeamPlayer($player, 100);
            }

            echo count($fairPlayers) . ' registered players' . PHP_EOL;
            echo count($fairUnregisteredPlayers) . ' unregistered players' . PHP_EOL;

            $fairTeamsService = new FairTeams($fairPlayers);
            // Unregistered players get a median score
            if (count($fairPlayers) > 2) {
                $median = (int)round($fairTeamsService->getMedianPlayerScore());
                echo 'Median score: ' . $median . PHP_EOL;
                foreach ($fairUnregisteredPlayers as $player) {
                    $matches = [];
                    // Name can contain a percentile score at the start, e.g. "0.85:PlayerNickname"
                    if (preg_match('/(0\.\d+):(.+)/', $player->player->nickname, $matches)) {
                        $percentile = (float)$matches[1];
                        $player->player->nickname = $matches[2];
                        $player->score = (int)round($fairTeamsService->getPercentileScore($percentile));
                    } else {
                        $player->score = $median;
                    }
                }
            }

            // Add unregistered players to the list
            $fairTeamsService->players = array_merge($fairPlayers, $fairUnregisteredPlayers);

            echo 'Total players: ' . count($fairTeamsService->players) . PHP_EOL;

            // Generate teams
            $teamCount = (int)ceil(count($fairTeamsService->players) / $tournament->teamSize);

            echo 'Generating ' . $teamCount . ' teams' . PHP_EOL;
            $teams = $fairTeamsService->getTeams($teamCount);

            echo 'Saving teams and players' . PHP_EOL;

            // Save teams and players
            $allTeams = [];
            $logger = new Logger(LOG_DIR, 'fair-teams');
            foreach ($teams as $fairTeam) {
                $team = new TournamentTeam();
                $team->tournament = $tournament;
                $team->name = $this->teamNames->generate();
                $allTeams[] = $team;
                $logger->debug('Team: ' . $team->name);
//                if (!$team->save()) {
//                    throw new \RuntimeException('Failed to save team ' . $team->name);
//                }

                foreach ($fairTeam->players as $fairPlayer) {
                    $player = $fairPlayer->player;
                    $player->team = $team;
                    $logger->debug('Player: ' . $player->nickname . ' (score: ' . $fairPlayer->score . ')');
//                    if (!$player->save()) {
//                        throw new \RuntimeException('Failed to save player ' . $player->nickname);
//                    }
                }
            }

            foreach ($allTeams as $team) {
                $team->save();
            }

            foreach ($fairTeamsService->players as $player) {
                $player->player->save();
            }

            echo 'Clearing cache' . PHP_EOL;
            /** @var Cache $cache */
            $cache = App::getServiceByType(Cache::class);
            $cache->clean(
                [
                    Cache::Tags => [
                        'tournament-' . $tournament->id . '-teams',
                        'tournament-' . $tournament->id . '-players',
                    ],
                ]
            );
            $request->passNotices[] = ['type' => 'success', 'content' => lang('Uloženo')];

            return $this->app->redirect(['tournament', $tournament->id, 'teams'], $request);
        }

        $this->params['tournament'] = $tournament;
        $this->params['addJs'] = ['modules/tournament/autoTeams.js'];
        return $this->view('../modules/Tournament/templates/autoTeams');
    }
}
