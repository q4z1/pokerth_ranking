<?php
namespace App\Http\Controllers;

use App\Services\GithubReleases;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

use Illuminate\Http\Request;

class DownloadsController extends Controller
{
    /**
     * Snapshot des neuesten PokerTH-Releases für die Download-Seite.
     *
     * Bevorzugt die von `php artisan downloads:sync` geschriebene Datei
     * (storage/app/downloads-latest.json). Fehlt oder verwaist sie, wird
     * einmalig live von GitHub geholt und für eine Stunde gecacht, damit die
     * Seite auch ohne vorherigen Sync-Lauf funktioniert.
     */
    private function latestRelease(): ?array
    {
        if(Storage::disk('local')->exists(GithubReleases::SNAPSHOT)){
            $data = json_decode(Storage::disk('local')->get(GithubReleases::SNAPSHOT), true);
            if(is_array($data) && !empty($data['files'])){
                return $data;
            }
        }

        $cached = Cache::get('downloads.latest_fallback');
        if(is_array($cached) && !empty($cached['files'])){
            return $cached;
        }

        $live = (new GithubReleases())->latest();
        if(is_array($live) && !empty($live['files'])){
            Cache::put('downloads.latest_fallback', $live, 3600);
            return $live;
        }

        return null;
    }

    public function allversions(Request $request){
        $snapshot = $this->latestRelease();

        if(!$snapshot || empty($snapshot['files'])){
            return ['status' => false, 'message' => 'No release information available'];
        }

        $icons = ['zip' => 'linux.svg', 'exe' => 'windows.svg', 'dmg' => 'mac.svg', 'apk' => 'android.svg', 'bz2' => 'linux.svg', 'run' => 'linux.svg', 'AppImage' => 'linux.svg', 'deb' => 'deb.svg'];

        $files = [];
        $shaLines = [];
        foreach($snapshot['files'] as $file){
            $ext = substr(strrchr($file['filename'], '.'), 1);
            if(!empty($file['sha256'])){
                $shaLines[] = $file['sha256'] . "  " . $file['filename'];
            }
            $f = [
                'filename' => $file['filename'],
                'url' => $file['url'],
                'source' => 'github',
                'sha256' => $file['sha256'] ?? null,
                'size' => $file['size'] ?? null,
            ];
            if(array_key_exists($ext, $icons)) $f['icon'] = "/images/" . $icons[$ext];
            $files[] = $f;
        }

        return ['status' => true, 'versions' => [[
            'version' => $snapshot['version'],
            'files' => $files,
            'sha256' => $shaLines ? implode("<br>", $shaLines) : "n/a",
            'source' => 'github',
            'github_url' => $snapshot['html_url'],
            'releases_url' => (new GithubReleases())->releasesUrl(),
            'published_at' => $snapshot['published_at'] ?? null,
            'readme' => !empty($snapshot['body']) ? nl2br(htmlspecialchars($snapshot['body'])) : null,
        ]]];
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
