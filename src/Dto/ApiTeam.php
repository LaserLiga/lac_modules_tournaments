<?php
declare(strict_types=1);
namespace LAC\Modules\Tournament\Dto;

class ApiTeam {
    public int $id;
    public string $name;
    public ?string $image = null;
    /** @var ApiPlayer[] */
    public array $players = [];
}