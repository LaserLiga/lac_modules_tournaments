<?php

namespace LAC\Modules\Tournament\Controllers;

use App\Core\App;
use App\Core\Info;
use Lsr\Core\Controllers\Controller;
use Lsr\Core\Exceptions\ValidationException;
use Lsr\Core\Requests\Request;
use Lsr\Exceptions\TemplateDoesNotExistException;
use Psr\Http\Message\ResponseInterface;

class SettingsController extends Controller
{
	/**
	 * @return void
	 * @throws ValidationException
	 * @throws TemplateDoesNotExistException
	 */
	public function tournaments(): ResponseInterface {
		return $this->view('../modules/Tournament/templates/settings');
	}

	public function saveTournament(Request $request): ResponseInterface {
		$mode = (string)$request->getPost('game_mode', '0-TEAM_Turnaj');
		Info::set('tournament_game_mode', $mode);

		if (empty($request->errors)) {
			if ($request->isAjax()) {
				return $this->respond(['status' => 'ok']);
			}
			$request->passNotices[] = ['type' => 'success', 'content' => lang('Úspěšně uloženo')];
			return App::redirect(['settings', 'tournament'], $request);
		}

		if ($request->isAjax()) {
			return $this->respond(['errors' => $request->errors], 500);
		}

		$request->passErrors = $request->errors;
		return App::redirect(['settings', 'tournament'], $request);
	}
}