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
    protected $signature = 'attack:check
                            {--enable : Switch the filter on by hand instead of measuring}
                            {--disable : Switch the filter off by hand instead of measuring}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Watch the hit rate and switch the Cloudflare filter on under attack';


    protected $last_state_file = 'visitors.txt';
    protected $log_file = 'visitor_log.txt';
    protected $graph_file = "visitors.png";

    // Filter enable/disable history with the reason, for the webserver log
    // dashboard. Kept separate from $log_file, which is just the raw 5 min
    // hit counts read by updateGraph() - mixing event rows into that would
    // break its awk parsing. Pre-created with open permissions on purpose:
    // this file is written by both the root cron and hand runs, and unlike
    // $enabled_since_file below there is no cheap delete-and-recreate for an
    // append-only log, so it needs to already be writable by both from the
    // start.
    protected $events_file = 'attack_events.log';

    // Remembers when the filter was switched on. The rule's own last_updated is
    // not usable for this any more: the crawler allowlist sync below patches the
    // rule whenever Google rotates its ranges, which would keep resetting it.
    protected $enabled_since_file = 'filter_enabled_since.txt';

    protected $hours = 96;
    protected $limit = 2500;

    // Kept short on purpose. A single scanner spraying 404s for a quarter of an
    // hour is enough to trip the detection, and leaving the whole zone behind a
    // challenge for days over that costs real visitors far more than the scan
    // ever cost us. After an hour the filter drops and the next run decides
    // again on current traffic, so a lasting attack simply switches it back on.
    protected $enabled_limit = 3600; // 3600 => 1 hour, disabled at the 55 minute run

    // Grace period after the auto disable, during which a traffic jump does not
    // count as an attack yet.
    //
    // At a minute this no longer reaches the next scheduled run, which is the
    // point: the run right after the auto disable judges the traffic normally,
    // sees the jump from filtered to unfiltered and switches straight back on
    // over the spike trigger. An attack that is still going is therefore let
    // through for one interval rather than two. What is left of the grace only
    // guards against a hand started run seconds after the automatic one.
    //
    // Raise it again to open a deliberate observation window: at 540 the filter
    // stays off for a full ten minutes, which is what it was set to on
    // 2026-08-15 while the scraper needed a chance to re-read the board.
    protected $window_limit = 60; // 60 => 1 minute, no longer spans a cron interval
    protected $window_file = 'filter_window_until.txt';

    // Switching the filter is worth a message again. This was off on 2026-08-15
    // while the filter cycled every half hour, which would have been roughly 96
    // messages a day. Failures are reported either way.
    protected $notify_switches = true;

    // Absolute hit rate that counts as an attack on its own. The $limit above only
    // catches a jump between two samples, which a slowly ramping attack never
    // produces: on 2026-08-13 the rate climbed from 157 to 5009 hits per 5 min over
    // 34 hours and the largest jump in the whole run was 1628. A quiet week sits at
    // a median of 157 and peaks at 1303, so 1200 is well clear of normal traffic.
    // Two samples in a row have to be above it, so a single outlier is not enough:
    // that peak of 1303 was one sample on its own, the rest of the week stayed below.
    protected $level_limit = 1200;

    // Distinct source IPs in the same 5 minute window, kept in its own state file
    // ($unique_ip_state_file) alongside the hit-count check above. A residential
    // proxy botnet fans out over far more distinct IPs than real traffic ever
    // does, so this catches that shape even when the raw hit count alone is
    // ambiguous. Over the ~2.5 days of log kept on disk the busiest 5 minute
    // window (pokerth only) saw 604 distinct IPs, an isolated sample with
    // neighbours of 316 and 177 -- the same kind of one-off the two-consecutive-
    // samples rule above already tolerates. The botnet burst on 2026-08-26 hit
    // 2002 and then 1274 distinct IPs in two consecutive samples, over 3x that
    // peak, so 700 with the same two-in-a-row rule stays clear of normal traffic
    // while catching an attack shaped like that one a full cycle earlier than
    // the hit count alone did (00:30 vs. 00:35).
    protected $unique_ip_limit = 700;
    protected $unique_ip_state_file = 'unique_ips.txt';

    // Every vhost the Cloudflare rule applies to, so an attack on a side domain is
    // seen as well. webclient.pokerth.net is deliberately absent, the rule excludes
    // that host, so counting its traffic could only trigger a filter that does not
    // cover it. Together the side domains add about a tenth of pokerth.net at the
    // median and next to nothing at the peak, so they do not shift the limit above.
    protected $access_logs = [
        'pokerth'    => '/var/log/nginx/pokerth_access.log',
        'bbc'        => '/var/log/nginx/bbc_access.log',
        'monthlycup' => '/var/log/nginx/monthlycup_access.log',
        'wec'        => '/var/log/nginx/wec_access.log',
        'test'       => '/var/log/nginx/pokerth_test_access.log',
    ];

    // Only the tail of a log is needed for a 5 minute window. Reading the files in
    // full is well over a gigabyte per run, they are not rotated.
    protected $access_log_tail = 20000000; // ~13x the busiest 5 minutes seen so far

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

        if($this->option('enable') && $this->option('disable')){
            $this->error('Use either --enable or --disable, not both.');

            return Command::FAILURE;
        }

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

        // Manual fallback. Switch and stop, no measuring, no graph, no crawler sync:
        // those belong to the scheduled run and would only slow the command down.
        if($this->option('enable') || $this->option('disable')){
            return $this->switchFilter($rule, $is_enabled);
        }

        $rule = $this->syncCrawlerAllowlist($rule);

        $enabled_since = $this->enabledSince($is_enabled, $last_update);

        $rule_disable = $rule_enable = $rule;
        $rule_disable['enabled'] = false;
        $rule_enable['enabled'] = true;

        date_default_timezone_set('UTC');
        $last5min = date("c", strtotime("-5 minutes"));

        [$hits, $uniqueIps] = $this->countHits($last5min);
        $total = array_sum($hits);
        $uniqueTotal = array_sum($uniqueIps);

        $diff = 0;

        if (!Storage::disk('local')->exists($this->last_state_file)){
            Storage::disk('local')->put($this->last_state_file, date("Y-m-d H:i:s") . "|" . $total . "|" . $diff);
        }

        $last = explode("|", Storage::disk('local')->get($this->last_state_file));
        $lastTotal = $last[1];
        $diff = $total - $lastTotal;

        Storage::disk('local')->put($this->last_state_file, date("Y-m-d H:i:s") . "|" . $total . "|" . $diff);

        // Fields 4 and 5 (unique IPs, per-vhost hits as JSON) were added later
        // for the webserver log dashboard; older rows simply lack them, which
        // WebServerLogAnalyzer accounts for.
        Storage::append($this->log_file, date("Y-m-d H:i:s") . "|" . $total . "|" . $diff
            . "|" . $uniqueTotal . "|" . json_encode($hits));

        // Same before/after comparison as the hit total above, kept in its own
        // file since it is not part of the visitor graph/log.
        if (!Storage::disk('local')->exists($this->unique_ip_state_file)){
            Storage::disk('local')->put($this->unique_ip_state_file, $uniqueTotal);
        }

        $lastUniqueTotal = (int) trim(Storage::disk('local')->get($this->unique_ip_state_file));
        Storage::disk('local')->put($this->unique_ip_state_file, $uniqueTotal);

        $this->updateGraph();

        if($is_enabled && (time() - $enabled_since) > ($this->enabled_limit - 300)){
            // Storage::append($this->log_file, date("Y-m-d H:i:s") . "|Filter disabled.");

            $this->patchRule($rule_disable);
            Storage::disk('local')->delete($this->enabled_since_file);

            $this->logEvent('disable', 'auto', 'active for ' . ($this->enabled_limit / 60) . ' min');

            // Hold the door open for $window_limit, otherwise the next run closes
            // it again straight away and the crawler gets nothing out of it.
            // Deleted first: a file left behind by the root cron cannot be
            // overwritten from a hand started run, put() would just fail quietly.
            Storage::disk('local')->delete($this->window_file);
            Storage::disk('local')->put($this->window_file, time() + $this->window_limit);

            if($this->notify_switches){
                $this->notify(date("Y-m-d H:i:s") . " - Normal - filter was active for "
                    . ($this->enabled_limit/60) . " min ... Filter deactivated.", 1127128);
            }

            return Command::SUCCESS;

        }

        // A sudden spike, or a rate that simply stays too high. The second one is
        // what catches an attack that ramps up slowly enough to hide in the noise.
        [$reason, $reasonType] = $this->detectReason($total, $diff, $lastTotal, $uniqueTotal, $lastUniqueTotal);

        // The traffic jumping right back is the whole point of an open window, so
        // it must not count as an attack while the window lasts.
        if(!is_null($reason) && $this->windowIsOpen()){
            $reason = null;
        }

        if(!is_null($reason)){
            // Storage::append($this->log_file, date("Y-m-d H:i:s") . "|Under Attack!");

            $reason .= " [" . $this->formatHits($hits) . "]";

            $this->patchRule($rule_enable);

            // Only report the switch itself. The level trigger stays true for as
            // long as the attack lasts, which would otherwise notify every 5 min.
            if(!$is_enabled){
                Storage::disk('local')->put($this->enabled_since_file, time());

                $this->logEvent('enable', 'auto', $reason, $reasonType, $total, $uniqueTotal, $hits);

                if($this->notify_switches){
                    $this->notify(date("Y-m-d H:i:s") . " - Critical - $reason ... Filter activated!", 14177041);
                }
            }
        }

        return Command::SUCCESS;
    }

    /**
     * The trigger check itself, pulled out of handle() so WebServerLogAnalyzer
     * can replay it over historical visitor_log.txt rows - attack_events.log
     * only exists from the point it was introduced onward, this lets the
     * dashboard reconstruct hit-spike/sustained-rate episodes that predate it
     * (unique-IP bursts can't be reconstructed this way: unique_ips.txt only
     * ever held the latest sample, never a history, before the same change).
     * Returns [reason, reasonType] or [null, null].
     */
    public function detectReason($total, $diff, $lastTotal, $uniqueTotal, $lastUniqueTotal)
    {
        if($diff > $this->limit){
            return ["last 5 min Hits diff: $diff", 'hit_spike'];
        } elseif($total > $this->level_limit && $lastTotal > $this->level_limit){
            return ["last 5 min Hits: $total (previous: $lastTotal, limit: " . $this->level_limit . ")", 'sustained_rate'];
        } elseif($uniqueTotal > $this->unique_ip_limit && $lastUniqueTotal > $this->unique_ip_limit){
            return ["last 5 min unique IPs: $uniqueTotal (previous: $lastUniqueTotal, limit: " . $this->unique_ip_limit . ")", 'unique_ip_burst'];
        }

        return [null, null];
    }

    /**
     * Whether the filter was switched off on purpose a moment ago and should be
     * left off for now. Closes itself once the window has run out.
     */
    protected function windowIsOpen()
    {
        if(!Storage::disk('local')->exists($this->window_file)){
            return false;
        }

        $until = (int) trim(Storage::disk('local')->get($this->window_file));

        if(time() >= $until){
            Storage::disk('local')->delete($this->window_file);

            return false;
        }

        return true;
    }

    /**
     * Switches the filter by hand, for when the automatic detection is not what we
     * want: an attack it does not recognise yet, or a false alarm to be cleared.
     * Enabling starts the auto-disable timer just like an automatic switch does, so
     * a filter turned on by hand does not stay on forever if it is then forgotten.
     */
    protected function switchFilter($rule, $is_enabled)
    {
        $enable = (bool) $this->option('enable');

        if($enable === (bool) $is_enabled){
            $this->info('Filter is already ' . ($enable ? 'enabled' : 'disabled') . ', nothing to do.');

            return Command::SUCCESS;
        }

        $rule['enabled'] = $enable;
        $this->patchRule($rule);

        // Written fresh rather than overwritten: the file is not always ours, a run
        // as root leaves it root owned and put() alone would then fail silently.
        Storage::disk('local')->delete($this->enabled_since_file);

        if($enable){
            Storage::disk('local')->put($this->enabled_since_file, time());
        }

        $this->logEvent($enable ? 'enable' : 'disable', 'manual');

        $this->info('Filter ' . ($enable ? 'enabled' : 'disabled') . '.');

        $this->notify(date("Y-m-d H:i:s") . " - Manual - Filter " . ($enable ? "activated" : "deactivated")
            . " by hand.", $enable ? 14177041 : 1127128);

        return Command::SUCCESS;
    }

    /**
     * Hits and distinct source IPs since $since per vhost, in one pass over each
     * log so a 5 minute window is not read from disk twice. All logs share
     * nginx's "main" format, so the timestamp is field 4 everywhere and compares
     * correctly as an ISO 8601 string.
     */
    protected function countHits($since)
    {
        $hits = [];
        $uniqueIps = [];

        foreach($this->access_logs as $name => $path){
            if(!is_readable($path)){
                continue; // a vhost that has never been hit has no log yet
            }

            // tail -n +2 drops the first line, which tail -c is free to cut in half.
            $command = "tail -c " . $this->access_log_tail . " " . escapeshellarg($path)
                . " | tail -n +2 | awk '$4 > \"[$since]\" { total++; if (!seen[$1]++) uniq++ } "
                . "END { print (total+0) \"|\" (uniq+0) }'";

            [$total, $unique] = explode("|", trim(shell_exec($command)));

            $hits[$name] = (int) $total;
            $uniqueIps[$name] = (int) $unique;
        }

        return [$hits, $uniqueIps];
    }

    /**
     * "pokerth: 4812, bbc: 41, wec: 9" for the alert, busiest vhost first, so it is
     * visible at a glance which site is being hit.
     */
    protected function formatHits($hits)
    {
        $hits = array_filter($hits);
        arsort($hits);

        $parts = [];
        foreach($hits as $name => $count){
            $parts[] = "$name: $count";
        }

        return empty($parts) ? "no hits" : implode(", ", $parts);
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

        $response = $this->cloudflare('PATCH', $url, json_encode($rule));

        // Nobody used to look at the answer. That was tolerable while the filter
        // was switched twice a week; now that it cycles every half hour, a call
        // failing quietly would leave the board wide open with nothing to show
        // for it. This is reported even when $notify_switches is off.
        $result = json_decode($response, true);

        if(!isset($result['success']) || !$result['success']){
            $this->notify(date("Y-m-d H:i:s") . " - ERROR - Cloudflare refused the rule update: "
                . substr(trim((string) $response), 0, 300), 14177041);
        }

        return $response;
    }

    /**
     * Appends one filter switch to $events_file for the webserver log
     * dashboard (WebServerLogAnalyzer). One line per switch, not per run, so
     * the file stays small; duration between an enable/disable pair is
     * derived when it is read, not stored here.
     */
    protected function logEvent($action, $trigger, $reason = null, $reasonType = null, $total = null, $uniqueTotal = null, $hits = null)
    {
        $entry = [
            'ts' => date('Y-m-d H:i:s'),
            'action' => $action, // 'enable' | 'disable'
            'trigger' => $trigger, // 'auto' | 'manual'
            'reason' => $reason,
            'reason_type' => $reasonType, // 'hit_spike' | 'sustained_rate' | 'unique_ip_burst'
            'total' => $total,
            'unique_ips' => $uniqueTotal,
            'hits' => $hits,
        ];

        Storage::append($this->events_file, json_encode($entry));
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
