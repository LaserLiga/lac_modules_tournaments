<?php

declare(strict_types=1);

namespace LAC\Modules\Tournament\Templates;

use App\GameModels\Vest;
use App\Gate\Models\MusicGroupDto;
use App\Models\MusicMode;
use App\Models\Playlist;
use App\Models\System;
use App\Templates\AutoFillParameters;
use LAC\Modules\Tournament\Models\Game;
use LAC\Modules\Tournament\Models\Tournament;
use Lsr\Core\Controllers\TemplateParameters;

class TournamentPlayTemplate extends TemplateParameters
{
    use AutoFillParameters;

    /** @var System[] */
    public array $systems = [];
    public System $system;
    public Tournament $tournament;
    public Game $game;
    /** @var Game[] */
    public array $upcomingGames = [];
    /** @var Vest[] */
    public array $vests = [];
    /** @var MusicMode[] */
    public array $musicModes = [];
    /** @var Playlist[] */
    public array $playlists = [];
    /** @var array<string, MusicGroupDto> */
    public array $musicGroups = [];
    /** @var array<string,string> */
    public array $gateActions = [];
    /** @var array<int, int> */
    public array $teamColors = [];
}
