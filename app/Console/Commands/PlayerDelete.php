<?php

namespace App\Console\Commands;

use App\Services\PlayerDeletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Löscht einen Account im Forum (phpBB) und in der Ranking-DB – dieselbe
 * Logik wie "Delete" auf der Players-Seite (PlayerDeletionService). Forenbeiträge
 * bleiben erhalten und laufen danach unter "Deleted".
 *
 * Existiert der Name nur auf einer Seite, wird nur diese gelöscht.
 */
class PlayerDelete extends Command
{
    protected $signature = 'player:delete
        {username : Exact player name (case-sensitive, as in forum and ranking db)}
        {--dry-run : Only show what would be deleted}
        {--force : Do not ask for confirmation}';

    protected $description = 'Delete a player in the forum (phpBB) and the ranking db; forum posts are kept as "Deleted"';

    public function handle(PlayerDeletionService $svc): int
    {
        $username = (string) $this->argument('username');
        $found = $svc->find($username);
        $forum = $found['forum'];
        $player = $found['player'];

        if ($found['ambiguous']) {
            $this->error("Ambiguous: more than one forum user or ranking player named '{$username}'.");
            return self::FAILURE;
        }
        if (!$forum && !$player) {
            $this->error("No forum user and no ranking player named '{$username}' (names are case-sensitive).");
            return self::FAILURE;
        }

        $sessions = $player ? DB::table('server_session')->where('player_id', $player->player_id)->count() : 0;
        $this->table(['', 'id', 'username', 'email', 'details'], [
            ['Forum', $forum->user_id ?? '-', $forum->username ?? 'NOT FOUND', $forum->user_email ?? '-',
                $forum ? "user_type={$forum->user_type}, posts={$forum->user_posts}" : '-'],
            ['Ranking', $player->player_id ?? '-', $player->username ?? 'NOT FOUND', $player->email ?? '-',
                $player ? "active={$player->active}, sessions={$sessions}" : '-'],
        ]);

        if (!$forum || !$player) {
            $this->warn('Account exists only on one side – only that side will be deleted.');
        }
        if ($forum && (int) $forum->user_posts > 0) {
            $this->warn("{$forum->user_posts} forum post(s) will be kept and shown as \"Deleted\".");
        }
        if ($forum && (int) $forum->user_type === 3) {
            $this->error('This is a forum founder account – refusing.');
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->line('Dry run – nothing deleted.');
            return self::SUCCESS;
        }
        if (!$this->option('force') && !$this->confirm("Permanently delete '{$username}'?", false)) {
            $this->line('Aborted.');
            return self::FAILURE;
        }

        try {
            $stats = $svc->delete($forum, $player);
        } catch (\Throwable $e) {
            $this->error('Delete failed, transaction rolled back – nothing was changed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->table(['table', 'rows'], collect($stats)->map(fn ($n, $t) => [$t, $n])->values()->all());
        $this->info("'{$username}' deleted.");
        return self::SUCCESS;
    }
}
