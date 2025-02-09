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
use App\Tools\GameLoading\GameLoader;
use App\Tools\GameLoading\LasermaxxGameLoader;
use DateInterval;
use DateTimeImmutable;
use LAC\Modules\Tournament\Models\Game;
use LAC\Modules\Tournament\Models\GameTeam;
use LAC\Modules\Tournament\Models\Group;
use LAC\Modules\Tournament\Models\MultiProgression;
use LAC\Modules\Tournament\Models\Player;
use LAC\Modules\Tournament\Models\Progression;
use LAC\Modules\Tournament\Models\Team as TournamentTeam;
use LAC\Modules\Tournament\Models\Tournament;
use LAC\Modules\Tournament\Models\TournamentPresetType;
use LAC\Modules\Tournament\Services\TournamentProvider;
use Lsr\Caching\Cache;
use Lsr\Core\Controllers\Controller;
use Lsr\Core\Requests\Dto\SuccessResponse;
use Lsr\Core\Requests\Request;
use Lsr\Core\Templating\Latte;
use Lsr\Lg\Results\Enums\GameModeType;
use Lsr\ObjectValidation\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface;
use TournamentGenerator\BlankTeam;
use TournamentGenerator\MultiProgression as MultiProgressionRozlos;
use TournamentGenerator\Progression as ProgressionRozlos;

/**
 * @phpstan-import-type GameData from LasermaxxGameLoader
 */
class TournamentController extends Controller
{
    public const EVO5_TEAM_COLORS = [0 => 1, 1 => 2, 2 => 0, 3 => 3, 4 => 4, 5 => 5];

    public function __construct(
        Latte $latte,
        private readonly TournamentProvider $tournamentProvider,
        private readonly GameLoader $gameLoader,
    ) {
        parent::__construct($latte);
    }

    public function index(): ResponseInterface {
        $this->params['tournaments'] = Tournament::query()->where('[active] = 1 AND DATE([start]) >= CURDATE()')->get();
        return $this->view('../modules/Tournament/templates/index');
    }

    public function oldTournaments(): ResponseInterface {
        $this->params['tournaments'] = Tournament::query()
                                                 ->where('[active] = 1 AND DATE([start]) < CURDATE()')
                                                 ->orderBy(
                                                     'start'
                                                 )
                                                 ->desc()
                                                 ->get();
        return $this->view('../modules/Tournament/templates/old');
    }

    public function show(Tournament $tournament): ResponseInterface {
        $this->params['tournament'] = $tournament;
        return $this->view('../modules/Tournament/templates/show');
    }

    public function rozlos(Tournament $tournament): ResponseInterface {
        $this->params['tournament'] = $tournament;
        $this->params['groups'] = $tournament->groups;
        $this->params['teams'] = $tournament->teams;
        $this->params['games'] = $tournament->getGames();
        $this->params['addJs'] = ['modules/tournament/rozlos.js'];
        return $this->view('../modules/Tournament/templates/rozlos');
    }

    public function rozlosProcess(Tournament $tournament, Request $request): ResponseInterface {
        $teams = $tournament->teams;
        $type = TournamentPresetType::tryFrom($request->getPost('tournament-type', ''));
        if ($type === null) {
            $request->addPassError(lang('Neplatný typ turnaje'));
            return $this->app->redirect(['tournament', $tournament->id, 'rozlos'], $request);
        }
        $tournament->gameLength = (int) $request->getPost('game-length', 15);
        $tournament->gamePause = (int) $request->getPost('game-pause', 5);
        $tournamentStart = (int) $request->getPost('tournament-start', 30);
        $iterations = (int) $request->getPost('game-repeat', 1);
        $args = $request->getPost('args', []);

        $tournamentRozlos = $this->tournamentProvider->createTournamentFromPreset(
            $type,
            $tournament,
            $iterations,
            is_array($args) ? $args : [],
        );

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
                $group->save();
                $groups[$group->id] = $group;
                $groupRozlos->setId($group->id);
                foreach ($groupRozlos->getProgressions() as $progression) {
                    if ($progression instanceof ProgressionRozlos) {
                        $progressions[] = $progression;
                    } else {
                        if ($progression instanceof MultiProgressionRozlos) {
                            // Generate key to prevent duplicates, because the progression is saved in multiple groups
                            $ids = array_map(
                                static fn(\TournamentGenerator\Group $g) => $g->getId(),
                                $progression->getFrom()
                            );
                            sort($ids);
                            $key = implode('-', $ids) . '->' . $group->id;
                            $multiProgressions[$key] = $progression;
                        }
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
            /** @var \TournamentGenerator\Game $roundGames */
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
                foreach ($gameRozlos->teams as $teamRozlos) {
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
                    $game->teams[] = $gameTeam;
                }
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
                    $keys[] = array_shift($groupTeamKey[$progression->to->id]);
                }
            }
            $progression->setKeys($keys);

            $progression->save();
        }

        foreach ($multiProgressions as $progressionRozlos) {
            $progression = new MultiProgression();
            $progression->tournament = $tournament;
            $progression->from = [];
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
        $request->passNotices[] = ['type' => 'success', 'content' => lang('Vygenerováno')];
        return $this->app->redirect(['tournament', $tournament->id, 'rozlos'], $request);
    }

    public function rozlosClear(Tournament $tournament, Request $request): ResponseInterface {
        $this->tournamentProvider->reset($tournament);
        $request->passNotices[] = ['type' => 'success', 'content' => lang('Rozlosování bylo smazáno')];
        return $this->app->redirect(['tournament', $tournament->id, 'rozlos'], $request);
    }

    public function sync(Request $request): ResponseInterface {
        if ($this->tournamentProvider->sync() && $this->tournamentProvider->syncUpcomingGames()) {
            $request->passNotices[] = ['type' => 'success', 'content' => lang('Synchronizováno')];
        } else {
            $request->addPassError(lang('Synchronizace se nezdařila'));
        }

        return $this->app->redirect(['tournament'], $request);
    }

    public function play(Tournament $tournament, ?Game $game = null): ResponseInterface {
        $this->params['tournament'] = $tournament;
        if (!isset($game)) {
            $game = $tournament->getPlannedGame();
        }
        if (!isset($game)) {
            $this->request->addPassError(lang('Nebyla nalezena žádná hra'));
            return $this->app->redirect(['tournament', $tournament->id], $this->request);
        }

        $this->params['game'] = $game;
        $this->params['upcomingGames'] = Game::query()->where(
            '[id_tournament] = %i AND [code] IS NULL',
            $tournament->id
        )->limit(20)->get();
        $this->params['vests'] = array_values(Vest::getForSystem('evo5'));
        $this->params['musicModes'] = MusicMode::getAll();
        $this->params['playlists'] = Playlist::getAll();
        $this->params['musicGroups'] = [];
        foreach ($this->params['musicModes'] as $music) {
            if (!$music->public) {
                continue;
            }
            $group = empty($music->group) ? $music->name : $music->group;
            $this->params['musicGroups'][$group] ??= new MusicGroupDto($group);
            $this->params['musicGroups'][$group]->music[] = $music;
        }

        $gateActionScreens = GateScreenModel::query()->where('trigger_value IS NOT NULL')->get();
        $this->params['gateActions'] = [];
        foreach ($gateActionScreens as $gateActionScreen) {
            $this->params['gateActions'][$gateActionScreen->triggerValue] = $gateActionScreen->triggerValue;
        }
        $this->params['teamColors'] = $this::EVO5_TEAM_COLORS;
        $this->params['addJs'] = ['modules/tournament/play.js'];
        $this->params['addCss'] = ['modules/tournament/play.css'];

        return $this->view('../modules/Tournament/templates/play');
    }

    public function playList(Tournament $tournament): ResponseInterface {
        $this->params['tournament'] = $tournament;
        $this->params['games'] = $tournament->getGames();
        return $this->view('../modules/Tournament/templates/playList');
    }

    public function playResults(Tournament $tournament, Game $game): ResponseInterface {
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

    public function playProcess(Tournament $tournament, Game $game, Request $request): ResponseInterface {
        /** @var GameData $data */
        $data = [
          'game-mode' => 1,
          'mode' => Info::get('tournament_game_mode', '0-TEAM_Turnaj'),
          'music'           => $request->getPost('music'),
          'meta' => [
            'tournament'      => $tournament->id,
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

        $meta = $this->gameLoader->loadGame('evo5', $data);

        return $this->respond(
            new SuccessResponse(
                values: [
                      'mode' => $meta['mode'],
                      'music' => $meta['music'],
                      'group' => $meta['group'] ?? null,
                      'groupName' => $meta['groupName'] ?? null
                    ],
            )
        );
    }

    public function updateBonusScore(Tournament $tournament, Game $game, Request $request): ResponseInterface {
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

    public function resetGame(Tournament $tournament, Game $game): ResponseInterface {
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

    public function progress(Tournament $tournament): ResponseInterface {
        return $this->respond(['progressed' => $this->tournamentProvider->progress($tournament)]);
    }

    public function create(): ResponseInterface {
        $this->params['addJs'] = ['modules/tournament/create.js'];
        return $this->view('../modules/Tournament/templates/create');
    }

    public function createProcess(Request $request): ResponseInterface {
        /** @var array{name:string,start:string,format:string,team_size:int,teams_in_game:int} $values */
        $values = [];
        $errors = [];

        // Validate request
        $values['name'] = (string) $request->getPost('name', '');
        if (empty($values['name'])) {
            $errors['name'] = lang('Název turnaje je povinný');
        } else {
            if (strlen($values['name']) > 100) {
                $errors['name'] = lang('Název turnaje nesmí být delší, než 100 znaků');
            }
        }
        $values['start'] = (string) $request->getPost('start', date('d.m.Y H:i'));
        if (empty($values['start'])) {
            $errors['start'] = lang('Začátek turnaje je povinný');
        } else {
            if (!strtotime($values['start'])) {
                $errors['start'] = lang('Začátek turnaje musí být datum a čas');
            }
        }
        $values['format'] = (string) $request->getPost('format', 'TEAM');
        if (empty($values['format'])) {
            $errors['format'] = lang('Formát turnaje je povinný');
        } else {
            if (GameModeType::tryFrom($values['format']) === null) {
                $errors['format'] = lang('Formát turnaje není validní');
            }
        }
        $values['team_size'] = (int) $request->getPost('team_size', 5);
        if ($values['format'] === GameModeType::TEAM->value && $values['team_size'] < 1) {
            $errors['team_size'] = lang('Velikost týmu je povinná a musí být kladné číslo');
        }
        $values['teams_in_game'] = (int) $request->getPost('teams_in_game', 2);
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

        $tournament->points->win = (int) $request->getPost('points_win', 3);
        $tournament->points->draw = (int) $request->getPost('points_draw', 1);
        $tournament->points->loss = (int) $request->getPost('points_loss', 0);
        $tournament->points->second = (int) $request->getPost('points_second', 2);
        $tournament->points->third = (int) $request->getPost('points_third', 1);

        try {
            if ($tournament->save()) {
                return $this->app->redirect(['tournament', $tournament->id], $request);
            }
        } catch (ValidationException $e) {
            $errors[] = $e->getMessage();
        }
        $errors[] = lang('Turnaj se nepodařilo uložit.');
        $this->params['values'] = $values;
        $this->params['errors'] = $errors;
        $this->params['addJs'] = ['modules/tournament/create.js'];
        return $this->view('../modules/Tournament/templates/create');
    }

    public function teams(Tournament $tournament, Request $request): ResponseInterface {
        $teams = $request->getPost('teams');
        if (isset($teams) && is_array($teams)) {
            foreach ($teams as $key => $teamData) {
                if (is_numeric($key)) {
                    $team = TournamentTeam::get((int) $key);
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
                        $player = Player::get((int) $pKey);
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
}
