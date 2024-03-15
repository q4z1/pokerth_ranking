<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Game extends Model
{
    use HasFactory;

    protected $table = "game";

    public $timestamps = false;

    public function players()
    {
        return $this->hasMany(GameHasPlayer::class, 'game_idgame', 'idgame')->with('player.ranking');
    }
}
