<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminPlayer extends Model
{
  use HasFactory;

  protected $table = "admin_player";

  protected $primaryKey = 'admin_idplayer';

  protected $hidden = [];

  public $timestamps = false;

}
