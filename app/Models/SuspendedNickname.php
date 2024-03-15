<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SuspendedNickname extends Model
{
    use HasFactory;

    public function player(){
        return $this->hasOne(Player::class, 'player_id', 'player_id');
    }
}
