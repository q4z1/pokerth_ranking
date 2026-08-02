<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportedAvatar extends Model
{
    use HasFactory;

    protected $table = "reported_avatar";

    /** Die Tabelle hat nur eine `timestamp`-Spalte, keine created_at/updated_at. */
    public $timestamps = false;

    protected $fillable = ['state'];

    /** Spalte, die auf den gemeldeten Spieler zeigt. */
    public const CREATOR_COLUMN = 'idplayer';
}
