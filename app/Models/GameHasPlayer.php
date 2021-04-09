<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameHasPlayer extends Model
{
    use HasFactory;

    protected $table = "game_has_player";

    public $timestamps = false;

    public function game()
    {
        return $this->hasOne(Game::class, 'idgame', 'game_idgame');
    }
}
