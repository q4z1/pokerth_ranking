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

    // Remembers when the filter was switched on. The rule's own last_updated is
    // not usable for this any more: the crawler allowlist sync below patches the
    // rule whenever Google rotates its ranges, which would keep resetting it.
    protected $enabled_since_file = 'filter_enabled_since.txt';

    protected $hours = 96;
    protected $limit = 2500;
    protected $enabled_limit = 604800; // 604800 => 7 days

    // Official crawler ranges, so verified search engine bots are not challenged
    // by the filter. cf.client.bot would do the same in one word, but that field
    // is not available on our plan, so we match the source IPs instead.
    protected $crawler_sources = [
        'Googlebot' => 'https://developers.google.com/static/crawling/ipranges/common-crawlers.json',
        'bingbot' => 'https://www.bing.com/toolbox/bingbot.json',
    ];
    protected $crawler_cache_file = 'crawler_ips.json';
    protected $crawler_cache_ttl = 21600; // 21600 => 6 hours
    protected $crawler_min_prefixes = 40; // sanity floor, a short answer never shrinks the allowlist
    protected $max_expression_length = 4096; // Cloudflare's ceiling for a rule expression

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {

        $url = "https://api.cloudflare.com/client/v4/zones/" . env('CF_ZONE_ID') . "/rulesets/" . env('CF_RULESET_ID');
        $response = $this->cloudflare('GET', $url);
        $rules = json_decode($response, true);

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

        $rule = $this->syncCrawlerAllowlist($rule);

        $enabled_since = $this->enabledSince($is_enabled, $last_update);

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

        if($is_enabled && (time() - $enabled_since) > ($this->enabled_limit - 300)){
            // Storage::append($this->log_file, date("Y-m-d H:i:s") . "|Filter disabled.");

            $this->patchRule($rule_disable);
            Storage::disk('local')->delete($this->enabled_since_file);

            $this->notify(date("Y-m-d H:i:s") . " - Normal - filter was active for "
                . ($this->enabled_limit/60) . " min ... Filter deactivated.", 1127128);

            return Command::SUCCESS;

        }

        if($diff > $this->limit){
            // Storage::append($this->log_file, date("Y-m-d H:i:s") . "|Under Attack!");

            $this->patchRule($rule_enable);
            if(!$is_enabled){
                Storage::disk('local')->put($this->enabled_since_file, time());
            }

            $this->notify(date("Y-m-d H:i:s") . " - Critical - last 5 min Hits diff: $diff ... Filter activated!", 14177041);
        }

        return Command::SUCCESS;
    }

    /**
     * Timestamp the filter has been enabled since, kept in a state file so that
     * patching the rule (crawler sync) does not restart the auto-disable timer.
     */
    protected function enabledSince($is_enabled, $last_update)
    {
        if(!$is_enabled){
            Storage::disk('local')->delete($this->enabled_since_file);
            return time();
        }

        // Enabled without us noticing, e.g. by hand in the dashboard.
        if(!Storage::disk('local')->exists($this->enabled_since_file)){
            Storage::disk('local')->put($this->enabled_since_file, $last_update);
            return $last_update;
        }

        return (int) trim(Storage::disk('local')->get($this->enabled_since_file));
    }

    /**
     * Keeps the trailing "and not ip.src in {...}" part of the rule expression in
     * sync with the published crawler ranges. Patches only when they changed, so
     * the rule is normally left alone.
     */
    protected function syncCrawlerAllowlist($rule)
    {
        $prefixes = $this->crawlerPrefixes();

        if(empty($prefixes)){
            return $rule; // sources unreachable, keep whatever is in the rule
        }

        $expression = preg_replace('/\s*and not ip\.src in \{[^}]*\}\s*$/', '', $rule['expression']);
        $expression = rtrim($expression) . ' and not ip.src in {' . implode(' ', $prefixes) . '}';

        if($expression === $rule['expression']){
            return $rule;
        }

        if(strlen($expression) > $this->max_expression_length){
            $this->notify(date("Y-m-d H:i:s") . " - Crawler allowlist NOT updated: expression would be "
                . strlen($expression) . " characters (limit " . $this->max_expression_length . ").", 14177041);

            return $rule;
        }

        $rule['expression'] = $expression;
        $this->patchRule($rule);

        $this->notify(date("Y-m-d H:i:s") . " - Crawler allowlist updated: " . count($prefixes) . " ranges.", 3447003);

        return $rule;
    }

    /**
     * Published Googlebot and bingbot ranges, merged and cached. Returns the last
     * known good list when a source is unreachable or answers with a short list.
     */
    protected function crawlerPrefixes()
    {
        $cached = null;

        if(Storage::disk('local')->exists($this->crawler_cache_file)){
            $cached = json_decode(Storage::disk('local')->get($this->crawler_cache_file), true);
            $age = time() - Storage::disk('local')->lastModified($this->crawler_cache_file);

            if(is_array($cached) && $age < $this->crawler_cache_ttl){
                return $cached;
            }
        }

        $prefixes = [];
        foreach($this->crawler_sources as $name => $url){
            $source = json_decode($this->httpGet($url), true);

            if(!isset($source['prefixes']) || !is_array($source['prefixes'])){
                return $cached; // unreachable or unexpected format
            }

            foreach($source['prefixes'] as $prefix){
                foreach(['ipv4Prefix', 'ipv6Prefix'] as $key){
                    if(!empty($prefix[$key])){
                        $prefixes[] = $prefix[$key];
                    }
                }
            }
        }

        if(count($prefixes) < $this->crawler_min_prefixes){
            return $cached; // truncated answer, do not shrink the allowlist
        }

        $prefixes = $this->mergePrefixes($prefixes);
        Storage::disk('local')->put($this->crawler_cache_file, json_encode($prefixes));

        return $prefixes;
    }

    /**
     * Merges a CIDR list into the shortest equivalent one: drops ranges contained
     * in another and joins sibling halves. Both sources together are roughly 340
     * ranges, more than fits into a rule expression, merged it is well below 100.
     */
    protected function mergePrefixes($cidrs)
    {
        $families = [];
        foreach($cidrs as $cidr){
            $parsed = $this->prefixToBits($cidr);
            if(is_null($parsed)){
                continue;
            }
            $families[$parsed[0]][] = $parsed[1];
        }

        $merged = [];
        foreach($families as $family => $prefixes){
            // SORT_STRING throughout: a bit pattern like "0000101000..." is a
            // numeric string, PHP would otherwise compare it as a float.
            $prefixes = array_unique($prefixes, SORT_STRING);
            sort($prefixes, SORT_STRING);

            // A range whose bit pattern starts with a shorter one is inside it.
            $kept = [];
            foreach($prefixes as $prefix){
                $previous = end($kept);
                if($previous !== false && strpos($prefix, $previous) === 0){
                    continue;
                }
                $kept[] = $prefix;
            }

            // ...0 next to ...1 is the parent range, repeat until nothing joins.
            do {
                $joined = [];
                $changed = false;

                for($i = 0; $i < count($kept); $i++){
                    $current = $kept[$i];
                    $next = isset($kept[$i + 1]) ? $kept[$i + 1] : null;

                    if(!is_null($next) && strlen($current) === strlen($next) && strlen($current) > 0
                        && substr($current, -1) === '0' && substr($current, 0, -1) === substr($next, 0, -1)){
                        $joined[] = substr($current, 0, -1);
                        $i++;
                        $changed = true;
                        continue;
                    }

                    $joined[] = $current;
                }

                $kept = $joined;
            } while($changed);

            foreach($kept as $prefix){
                $merged[] = $this->bitsToPrefix($prefix, $family);
            }
        }

        return $merged;
    }

    /**
     * "66.249.64.0/23" => [4, "0100001011111001010000000"], null when unparsable.
     * The address family is kept alongside so that v4 and v6 never mix.
     */
    protected function prefixToBits($cidr)
    {
        $parts = explode('/', trim($cidr), 2);
        $packed = @inet_pton($parts[0]);

        if($packed === false){
            return null;
        }

        $family = strlen($packed); // 4 => IPv4, 16 => IPv6
        $length = isset($parts[1]) ? (int) $parts[1] : $family * 8;

        if($length < 0 || $length > $family * 8){
            return null;
        }

        $bits = '';
        foreach(str_split($packed) as $byte){
            $bits .= sprintf('%08b', ord($byte));
        }

        return [$family, substr($bits, 0, $length)];
    }

    protected function bitsToPrefix($bits, $family)
    {
        $packed = '';
        foreach(str_split(str_pad($bits, $family * 8, '0'), 8) as $byte){
            $packed .= chr(bindec($byte));
        }

        return inet_ntop($packed) . '/' . strlen($bits);
    }

    protected function httpGet($url)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_TIMEOUT, 20);
        curl_setopt($curl, CURLOPT_USERAGENT, 'PokerTH/2.0 (Qt Network)');
        $response = curl_exec($curl);
        curl_close($curl);

        return $response;
    }

    protected function cloudflare($method, $url, $data = null)
    {
        $headers = array('Content-Type: application/json', 'X-Auth-Email: ' . env('CF_EMAIL'), 'X-Auth-Key: ' . env('CF_API_KEY'));
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        if(!is_null($data)){
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        }
        $response = curl_exec($curl);
        curl_close($curl);

        return $response;
    }

    protected function patchRule($rule)
    {
        $url = "https://api.cloudflare.com/client/v4/zones/" . env('CF_ZONE_ID') . "/rulesets/" . env('CF_RULESET_ID') . "/rules/" . env('CF_RULE_ID');

        return $this->cloudflare('PATCH', $url, json_encode($rule));
    }

    protected function notify($title, $color)
    {
        $data = json_encode(["embeds" => [["title" => $title, "color" => $color]]]);
        $headers = array('Content-Type: application/json', 'Accept: application/json');
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, env('DISCORD_ATTACK_WEBHOOK'));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($curl);
        curl_close($curl);

        return $response;
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
