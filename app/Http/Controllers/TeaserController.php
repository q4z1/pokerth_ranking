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
        $now = Carbon::now();
        $weekKey = $now->format('oW');
        $files = Storage::disk('public')->files('teaser');

        $nextWeekStart = $now->copy()->startOfWeek(Carbon::MONDAY)->addWeek();
        $seconds = $nextWeekStart->diffInSeconds($now);

        $cacheKey = "random_weekly_teaser_{$weekKey}";
        $random_list = Cache::remember($cacheKey, $seconds, function () use ($files) {
            $list = $files;
            shuffle($list);
            return $list;
        });

        $index = intval($now->format('W')) % max(1, count($random_list));
        $file = $random_list[$index] ?? ($files[0] ?? null);
        if (! $file) {
            abort(404);
        }

        return response()->file(storage_path('app/public/' . $file), ['Content-Type' => 'image/png']);
    }
}
