<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Player extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = "player";

    protected $primaryKey = 'player_id';

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
