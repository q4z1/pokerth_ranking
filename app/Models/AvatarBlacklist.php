<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Gesperrte Avatare. Wird bislang vom Game-Server ausgewertet (er verteilt
 * gesperrte Avatare nicht mehr im Spiel); die Webseite zieht über
 * \App\Services\AvatarBlacklistService nach.
 */
class AvatarBlacklist extends Model
{
    use HasFactory;

    protected $table = "avatar_blacklist";

    /** Die Tabelle hat nur id + avatar_hash, keine created_at/updated_at. */
    public $timestamps = false;

    protected $fillable = ['avatar_hash'];
}
