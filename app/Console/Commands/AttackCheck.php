<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AttackCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attack:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $file = "visitors.txt";
        $log = "visitor_log.txt";
        $limit = 2000;
        $enabled_limit = 1800;

        $url = "https://api.cloudflare.com/client/v4/zones/" . env('CF_ZONE_ID') . "/rulesets/" . env('CF_RULESET_ID');
        $headers = array('Content-Type: application/json', 'X-Auth-Email: ' . env('CF_EMAIL'), 'X-Auth-Key: ' . env('CF_API_KEY'));
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
        $response = curl_exec($curl);
        $rules = json_decode($response, true);
        curl_close($curl);

        $rule = null;
        foreach($rules['result']['rules'] as $tRule){
            if($tRule['id'] == env('CF_RULE_ID')){
                $rule = $tRule;
            }
        }

        if(is_null($rule)){
            dd("Rule not found!");
        }

        $last_update = strtotime($rule['last_updated']);

        unset($rule['last_updated']);
        unset($rule['ref']);
        unset($rule['version']);

        $is_enabled = $rule['enabled'];

        $rule_disable = $rule_enable = $rule;
        $rule_disable['enabled'] = true;

        date_default_timezone_set('UTC');
        $last5min = date("c", strtotime("-5 minutes"));

        $command =  "cat /var/log/nginx/pokerth_access.log | awk '$4 > \"[$last5min]\"' | wc -l";
        $total = trim(shell_exec($command));

        $diff = 0;

        if (!Storage::disk('local')->exists($file)){
             Storage::disk('local')->put($file, date("Y-m-d H:i:s") . "|" . $total . "|" . $diff);
        }

        $last = explode("|", Storage::disk('local')->get($file));
        $lastTotal = $last[1];
        $diff = $total - $lastTotal;

        Storage::disk('local')->put($file, date("Y-m-d H:i:s") . "|" . $total . "|" . $diff);

        Storage::append($log, date("Y-m-d H:i:s") . "|" . $total . "|" . $diff);

        if($is_enabled && (time() - $last_update) > $enabled_limit){
            Storage::append($log, date("Y-m-d H:i:s") . "|Filter disabled.");

            $data = json_encode($rule_enable);
            $url = "https://api.cloudflare.com/client/v4/zones/" . env('CF_ZONE_ID') . "/rulesets/" . env('CF_RULESET_ID') . "/rules/" . env('CF_RULE_ID');
            $headers = array('Content-Type: application/json', 'X-Auth-Email: ' . env('CF_EMAIL'), 'X-Auth-Key: ' . env('CF_API_KEY'));
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            $response = curl_exec($curl);
            curl_close($curl);

            $data = "{\"embeds\": [{ \"title\": \"".date("Y-m-d H:i:s")." - Normal - filter was active for $enabled_limit sec ... Filter deactivated.\", \"color\": 1127128 }]}";
            $headers = array('Content-Type: application/json', 'Accept: application/json');
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, env('DISCORD_ATTACK_WEBHOOK'));
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            $response = curl_exec($curl);
            curl_close($curl);

            return Command::SUCCESS;

        }

        if($diff > $limit){
            Storage::append($log, date("Y-m-d H:i:s") . "|Under Attack!");

            $data = json_encode($rule_disable);
            $url = "https://api.cloudflare.com/client/v4/zones/" . env('CF_ZONE_ID') . "/rulesets/" . env('CF_RULESET_ID') . "/rules/" . env('CF_RULE_ID');
            $headers = array('Content-Type: application/json', 'X-Auth-Email: ' . env('CF_EMAIL'), 'X-Auth-Key: ' . env('CF_API_KEY'));
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            $response = curl_exec($curl);
            curl_close($curl);

            $data = "{\"embeds\": [{ \"title\": \"".date("Y-m-d H:i:s")." - Critical - last 5 min Hits >$limit ... Filter activated!\", \"color\": 14177041 }]}";
            $headers = array('Content-Type: application/json', 'Accept: application/json');
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, env('DISCORD_ATTACK_WEBHOOK'));
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            $response = curl_exec($curl);
            curl_close($curl);
        }

        return Command::SUCCESS;
    }
}
