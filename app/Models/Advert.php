<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Advert extends Model
{
    use HasFactory;

    protected $fillable = ['position', 'content', 'order', 'start', 'end'];

    protected $casts = [
        'order' => 'integer',
        'start' => 'date:Y-m-d',
        'end'   => 'date:Y-m-d',
    ];
}
