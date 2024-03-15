<?php

namespace App\Http\Controllers;

use App\Models\Advert;
use Illuminate\Http\Request;

class AdvertController extends Controller
{
    public function getAdverts(Request $request, $position){
        $adverts = Advert::where([['position', $position],['start', '<', date("Y-m-d")],['end', '>', date("Y-m-d")]])->orderBy('order', 'ASC')->get();
        return ['success' => ($adverts) ? true : false, 'adverts' => $adverts];
    }
}
