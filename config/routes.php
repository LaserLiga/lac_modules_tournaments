<?php

use LAC\Modules\Tournament\Controllers\Api\Tournaments;
use LAC\Modules\Tournament\Controllers\SettingsController;
use LAC\Modules\Tournament\Controllers\TournamentController;
use LAC\Modules\Tournament\Controllers\TournamentResults;

/** @var \Lsr\Core\Routing\Router $this */

$tournamentGroup = $this->group('tournament')
    ->get('', [TournamentController::class, 'index'])
    ->name('tournaments')
    ->get('create', [TournamentController::class, 'create'])
    ->name('tournament-create')
    ->post('create', [TournamentController::class, 'createProcess'])
    ->get('old', [TournamentController::class, 'oldTournaments'])
    ->get('sync', [TournamentController::class, 'sync']);

$tournamentGroup->group('{id}')
    ->get('', [TournamentController::class, 'show'])
    ->get('rozlos', [TournamentController::class, 'rozlos'])->name('tournament-rozlos')
    ->get('results', [TournamentResults::class, 'results'])->name('tournament-results')
    ->get('gate', [TournamentResults::class, 'gate'])->name('gate-tournament')
    ->get('gate/{gate}', [TournamentResults::class, 'gate'])->name('gate-slug-tournament')
    ->get('teams', [TournamentController::class, 'teams'])->name('tournament-teams')
    ->post('teams', [TournamentController::class, 'teams'])
    ->get('teams/auto', [TournamentController::class, 'autoTeams'])->name('tournament-teams-auto')
    ->post('teams/auto', [TournamentController::class, 'autoTeams'])
    ->get('players', [TournamentController::class, 'players'])->name('tournament-players')
    ->post('players', [TournamentController::class, 'players'])
    ->post('rozlos', [TournamentController::class, 'rozlosProcess'])
    ->get('rozlos/clear', [TournamentController::class, 'rozlosClear'])
    ->post('progress', [TournamentController::class, 'progress'])
    ->group('play')
    ->get('', [TournamentController::class, 'play'])->name('tournament-play')
    ->get('list', [TournamentController::class, 'playList'])->name('tournament-play-list')
    ->group('{gameId}')
    ->get('', [TournamentController::class, 'play'])->name('tournament-play-game')
    ->post('', [TournamentController::class, 'playProcess'])
    ->get('results', [TournamentController::class, 'playResults'])
    ->post('bonus', [TournamentController::class, 'updateBonusScore'])
    ->post('reset', [TournamentController::class, 'resetGame']);

$this->group('api/tournament')
    ->get('/', [Tournaments::class, 'getAll'])
    ->get('/{id}', [Tournaments::class, 'get'])
    ->post('/sync', [Tournaments::class, 'sync'])
    ->post('/{id}/sync/games', [Tournaments::class, 'syncGames'])
    ->post('/{id}/recalc', [Tournaments::class, 'recalculatePoints']);

$this->group('settings/tournament')
    ->get('', [SettingsController::class, 'tournaments'])
    ->name('settings-tournament')
    ->post('', [SettingsController::class, 'saveTournament']);
