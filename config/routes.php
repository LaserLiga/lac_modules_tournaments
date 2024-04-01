<?php

use LAC\Modules\Tournament\Controllers\Api\Tournaments;
use LAC\Modules\Tournament\Controllers\SettingsController;
use LAC\Modules\Tournament\Controllers\TournamentController;
use LAC\Modules\Tournament\Controllers\TournamentResults;
use Lsr\Core\Routing\Route;

$tournamentGroup = Route::group('tournament')
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
	->get('gate', [TournamentResults::class, 'gate'])->name('tournament-gate')
	->get('gate/{gate}', [TournamentResults::class, 'gate'])->name('tournament-gate-slug')
	->get('teams', [TournamentController::class, 'teams'])->name('tournament-teams')
                ->post('teams', [TournamentController::class, 'teams'])
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

Route::group('api/tournament')
	->get('/', [Tournaments::class, 'getAll'])
	->get('/{id}', [Tournaments::class, 'get'])
	->post('/sync', [Tournaments::class, 'sync'])
	->post('/{id}/sync/games', [Tournaments::class, 'syncGames'])
	->post('/{id}/recalc', [Tournaments::class, 'recalculatePoints']);

Route::group('settings/tournament')
     ->get('', [SettingsController::class, 'tournaments'])
     ->name('settings-tournament')
     ->post('', [SettingsController::class, 'saveTournament']);