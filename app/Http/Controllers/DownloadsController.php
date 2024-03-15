<?php
namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

use Illuminate\Http\Request;

class DownloadsController extends Controller
{
    public function files(Request $request){
        $icons = ['zip' => 'windows.svg', 'exe' => 'windows.svg', 'dmg' => 'mac.svg', 'apk' => 'android.svg', 'bz2' => 'linux.svg', 'run' => 'linux.svg'];
        $path = base_path() . "/../download/client/";
        if(is_dir($path)){
            $files = [];
            $md5sums = "n/a";
            $dir = array_diff(scandir($path), array('..', '.'));
            foreach($dir as $file){
                if(strpos($file, "MD5SUMS") !== false){
                    $md5sums = str_replace("\n", "<br>", file_get_contents($path . $file));
                    continue;
                }
                $ext = substr(strrchr($file, '.'), 1);
                $f = ['filename' => $file, 'url' => "/download/client/" . $file];
                if(array_key_exists($ext, $icons)) $f['icon'] = "/images/" . $icons[$ext];
                $files[] = $f;
            }
            rsort($files);
            return ['status' => true, 'files' => $files, 'md5' => $md5sums];
        }
        return ['status' => false];
    }

    public function styles(Request $request){
        $path = base_path() . "/../download/styles/cards/";
        if(is_dir($path)){
            $cards = [];
            $dir = array_diff(scandir($path), array('..', '.'));
            foreach($dir as $file){
                if(strpos($file, '.zip') !== false){
                    $preview = (!file_exists($path . str_replace("zip", "png", $file))) ? null : "/download/styles/cards/" . str_replace("zip", "png", $file); 
                    $f = ['filename' => $file, 'url' => "/download/styles/cards/" . $file, 'preview' => $preview];
                    $cards[] = $f;
                }
            }
        }
        $path = base_path() . "/../download/styles/table/";
        if(is_dir($path)){
            $tables = [];
            $dir = array_diff(scandir($path), array('..', '.'));
            foreach($dir as $file){
                if(strpos($file, '.zip') !== false){
                    $preview = (!file_exists($path . str_replace("zip", "png", $file))) ? null : "/download/styles/table/" . str_replace("zip", "png", $file); 
                    $f = ['filename' => $file, 'url' => "/download/styles/table/" . $file, 'preview' => $preview];
                    $tables[] = $f;
                }
            }
        }
        return ['status' => true, 'cards' => $cards, 'tables' => $tables];
    }
}
