<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlayerRanking extends Model
{
    use HasFactory;

    protected $table = "player_ranking";

    protected $primaryKey = 'player_id';

    public $timestamps = false;
}
