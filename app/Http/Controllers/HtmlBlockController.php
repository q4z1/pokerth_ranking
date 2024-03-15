<?php

namespace App\Http\Controllers;

use App\Models\HtmlBlock;
use Illuminate\Http\Request;

class HtmlBlockController extends Controller
{
    public function getBlock(Request $request, $title){
        $block = HtmlBlock::where([['title', $title],['active', 1]])->first();
        return ['success' => ($block) ? true : false, 'html' => ($block) ? $block->html : null];
    }
}
