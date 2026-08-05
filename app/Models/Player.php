<?php

namespace App\Models;

use App\Services\AvatarBlacklistService;
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

    /**
     * Gesperrte Avatare werden in jeder API-Antwort ausgeblendet.
     *
     * Die Spalte selbst bleibt unangetastet – sie gehört dem Game-Server, der
     * sie beim nächsten Login ohnehin wieder schreiben würde. Das Frontend
     * rendert bei leerem Hash keinen Avatar (siehe PlayerComponent.vue).
     */
    public function getAvatarHashAttribute($value)
    {
        return app(AvatarBlacklistService::class)->isBlacklisted($value) ? '' : $value;
    }

    public function getAvatarMimeAttribute($value)
    {
        $hash = $this->attributes['avatar_hash'] ?? null;
        return app(AvatarBlacklistService::class)->isBlacklisted($hash) ? '' : $value;
    }

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
