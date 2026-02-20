<?php
namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

use Illuminate\Http\Request;

class DownloadsController extends Controller
{
    public function oldfiles(Request $request){
        $icons = ['zip' => 'windows.svg', 'exe' => 'windows.svg', 'dmg' => 'mac.svg', 'apk' => 'android.svg', 'bz2' => 'linux.svg', 'run' => 'linux.svg', 'AppImage' => 'linux.svg'];
        $path = base_path() . "/../download/client/1.1.2/";
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

    public function currentfiles(Request $request){
        $icons = ['zip' => 'linux.svg', 'exe' => 'windows.svg', 'dmg' => 'mac.svg', 'apk' => 'android.svg', 'bz2' => 'linux.svg', 'run' => 'linux.svg', 'AppImage' => 'linux.svg'];
        $path = base_path() . "/../download/client/2.0/";
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

    public function allversions(Request $request){
        $icons = ['zip' => 'windows.svg', 'exe' => 'windows.svg', 'dmg' => 'mac.svg', 'apk' => 'android.svg', 'bz2' => 'linux.svg', 'run' => 'linux.svg', 'AppImage' => 'linux.svg'];
        $clientPath = base_path() . "/../download/client/";
        
        if(!is_dir($clientPath)){
            return ['status' => false, 'message' => 'Client directory not found'];
        }
        
        $versions = [];
        $dirs = array_diff(scandir($clientPath), array('..', '.'));
        
        foreach($dirs as $versionDir){
            $versionPath = $clientPath . $versionDir . "/";
            if(is_dir($versionPath) && substr($versionDir, 0, 1) !== '_'){
                $files = [];
                $md5sums = "n/a";
                $dirFiles = array_diff(scandir($versionPath), array('..', '.'));
                
                foreach($dirFiles as $file){
                    if(strpos($file, "MD5SUMS") !== false){
                        $md5sums = str_replace("\n", "<br>", file_get_contents($versionPath . $file));
                        continue;
                    }
                    $ext = substr(strrchr($file, '.'), 1);
                    $f = ['filename' => $file, 'url' => "/download/client/" . $versionDir . "/" . $file];
                    if(array_key_exists($ext, $icons)) $f['icon'] = "/images/" . $icons[$ext];
                    $files[] = $f;
                }
                
                rsort($files);
                $versions[] = [
                    'version' => $versionDir,
                    'files' => $files,
                    'md5' => $md5sums
                ];
            }
        }
        
        // Sortiere Versionen absteigend (neuere zuerst)
        usort($versions, function($a, $b) {
            return version_compare($b['version'], $a['version']);
        });
        
        return ['status' => true, 'versions' => $versions];
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
