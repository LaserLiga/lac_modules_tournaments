<?php
declare(strict_types=1);
namespace LAC\Modules\Tournament\Dto;

class ApiLeague {
    public int $id;
    public string $name;
    public ?string $image = null;
    public ?string $description = null;
}