<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use Amenadiel\JpGraph\Graph;
use Amenadiel\JpGraph\Plot;

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


    protected $last_state_file = 'visitors.txt';
    protected $log_file = 'visitor_log.txt';
    protected $graph_file = "visitors.png";

    protected $hours = 96;
    protected $limit = 1500;
    protected $enabled_limit = 604800; // 604800 => 7 days

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {

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
        // $is_enabled = false; // debug

        $rule_disable = $rule_enable = $rule;
        $rule_disable['enabled'] = false;
        $rule_enable['enabled'] = true;

        date_default_timezone_set('UTC');
        $last5min = date("c", strtotime("-5 minutes"));

        $command =  "cat /var/log/nginx/pokerth_access.log | awk '$4 > \"[$last5min]\"' | wc -l";
        $total = trim(shell_exec($command));

        $diff = 0;

        if (!Storage::disk('local')->exists($this->last_state_file)){
            Storage::disk('local')->put($this->last_state_file, date("Y-m-d H:i:s") . "|" . $total . "|" . $diff);
        }

        $last = explode("|", Storage::disk('local')->get($this->last_state_file));
        $lastTotal = $last[1];
        $diff = $total - $lastTotal;

        Storage::disk('local')->put($this->last_state_file, date("Y-m-d H:i:s") . "|" . $total . "|" . $diff);

        Storage::append($this->log_file, date("Y-m-d H:i:s") . "|" . $total . "|" . $diff);

        $this->updateGraph();

        if($is_enabled && (time() - $last_update) > ($this->enabled_limit - 300)){
            // Storage::append($this->log_file, date("Y-m-d H:i:s") . "|Filter disabled.");

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

            $data = "{\"embeds\": [{ \"title\": \"" . date("Y-m-d H:i:s") . " - Normal - filter was active for "
                . ($this->enabled_limit/60) . " min ... Filter deactivated.\", \"color\": 1127128 }]}";
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

        if($diff > $this->limit){
            // Storage::append($this->log_file, date("Y-m-d H:i:s") . "|Under Attack!");

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

            $data = "{\"embeds\": [{ \"title\": \"".date("Y-m-d H:i:s")." - Critical - last 5 min Hits diff: $diff ... Filter activated!\", \"color\": 14177041 }]}";
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

    public function updateGraph()
    {
        // dd('updateGraph');
        $lastHours = date("Y-m-d H:i:s", strtotime("-" . $this->hours . " hours"));

        $command =  "cat /var/www/pokerth/pthranking/storage/app/" . $this->log_file . " | awk -F  '|' '$1 > \"$lastHours\"'";
        $lines = explode("\n", trim(shell_exec($command)));
        $log = [];
        $datay = [];
        foreach($lines as $line){
            $log[] = explode("|", $line);
            $datay[] = explode("|", $line)[1];
        }
        // Create the Line Graph.
        // $datay    = [1.23, 1.9, 1.6, 3.1, 3.4, 2.8, 2.1, 1.9];
        $__width  = 1640;
        $__height = 800;
        $graph = new Graph\Graph($__width, $__height);
        // $graph->ygrid->Show(false, false);
        $graph->SetScale('textlin');

        $graph->SetColor($aTxtColor='black', $aFillColor='grey', $aBorderColor='black');

        $graph->img->SetMargin(40, 40, 40, 40);
        $graph->SetShadow();

        $graph->title->Set('Webserver Hits last ' . $this->hours . ' hours');
        $graph->title->SetFont(FF_FONT1, FS_BOLD);

        $p1 = new Plot\LinePlot($datay);
        $p1->SetFillColor('orange');
        $p1->mark->SetType(MARK_FILLEDCIRCLE);
        $p1->mark->SetFillColor('red');
        $p1->mark->SetWidth(4);
        $graph->Add($p1);

        ob_start();
        $graph->Stroke();
        $data = ob_get_contents();
        ob_end_clean();

        Storage::disk('public')->put($this->graph_file, $data);

    }
}
