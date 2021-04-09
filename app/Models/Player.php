<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    use HasFactory;

    protected $table = "player";

    protected $hidden = ['password', 'email', 'fp', 'fpnew', 'last_ip', 'active', 'blocked', 'act_key', 'last_games'];

    public $timestamps = false;

    public function ranking()
    {
        return $this->hasOneThrough(
            PlayerRanking::class, Player::class,
            'player_id',
            'player_id',
            'player_id',
            'player_id' 
        );
    }
}
