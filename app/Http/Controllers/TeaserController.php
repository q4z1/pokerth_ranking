<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class TeaserController extends Controller
{
    public function weekly(Request $request)
    {
        // $this->reset_weekly();
        $weeknumber = Carbon::now()->format("W");
        $files = Storage::disk('public')->files('teaser');
        $random_list = $files;
        shuffle($random_list);
        $random_list = Cache::rememberForever("random_weekly_teaser", function () use ($files) {
            $list = $files; 
            shuffle($list);
            return $list;
        });
        $index = $weeknumber % count($random_list);
        $file = $random_list[$index];
        return response()->file(storage_path('app/public/' . $file), ['Content-type','image/png']);
    }

    public function reset_weekly(){
        Cache::forget("random_weekly_teaser");
    }
}
