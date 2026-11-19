<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Löscht einen Account komplett: Forum-User (phpBB, DB "pokerth") und
 * Ranking-Account (player + player_ranking).
 *
 * Forum-Seite nach phpBB user_delete() im Modus "retain": Beiträge bleiben
 * erhalten und gehen an den Anonymous-User (Name "Deleted"). Alles läuft in
 * einer Transaktion (InnoDB, gleiche MariaDB-Verbindung) – bricht ein Schritt
 * ab, bleibt der Account vollständig erhalten. Die Avatar-Datei wird erst nach
 * dem Commit entfernt.
 *
 * Benutzt von AdminController (Players-Seite) und `php artisan player:delete`.
 */
class PlayerDeletionService
{
    private const FORUM_DB = 'pokerth';

    /** Tabellen mit Spalte user_id, deren Zeilen phpBB beim Löschen entfernt. */
    private const USER_TABLES = [
        'phpbb_user_group', 'phpbb_topics_watch', 'phpbb_forums_watch', 'phpbb_acl_users', 'phpbb_topics_track',
        'phpbb_topics_posted', 'phpbb_forums_track', 'phpbb_profile_fields_data', 'phpbb_moderator_cache',
        'phpbb_drafts', 'phpbb_bookmarks', 'phpbb_sessions_keys', 'phpbb_privmsgs_folder', 'phpbb_privmsgs_rules',
        'phpbb_oauth_tokens', 'phpbb_oauth_states', 'phpbb_oauth_accounts', 'phpbb_user_notifications',
        'phpbb_reports', 'phpbb_users',
    ];

    /**
     * Forum-User und Ranking-Player zu einem exakten Namen.
     *
     * @return array{forum: ?object, player: ?object, ambiguous: bool}
     */
    public function find(string $username): array
    {
        $forum = DB::table(self::FORUM_DB . '.phpbb_users')
            ->where('username', $username)
            ->get(['user_id', 'username', 'user_email', 'user_type', 'user_posts', 'user_avatar', 'user_avatar_type']);
        $player = DB::table('player')
            ->where('username', $username)
            ->get(['player_id', 'username', 'email', 'active']);

        return [
            'forum'     => $forum->first(),
            'player'    => $player->first(),
            'ambiguous' => $forum->count() > 1 || $player->count() > 1,
        ];
    }

    /**
     * @param object|null $forum  Zeile aus find()['forum']
     * @param object|null $player Zeile aus find()['player']
     * @return array<string,int> Anzahl gelöschter/geänderter Zeilen je Tabelle
     */
    public function delete(?object $forum, ?object $player): array
    {
        $uid = $forum ? (int) $forum->user_id : null;
        if ($uid !== null && $uid <= 1) {
            throw new \RuntimeException('Refusing to delete the anonymous user.');
        }

        $stats = DB::transaction(function () use ($forum, $player, $uid) {
            $stats = [];
            if ($uid !== null) $stats += $this->deleteForumUser($forum, $uid);
            if ($player) {
                $pid = (int) $player->player_id;
                $stats['player_ranking'] = DB::table('player_ranking')->where('player_id', $pid)->delete();
                $stats['player'] = DB::table('player')->where('player_id', $pid)->delete();
            }
            return $stats;
        });

        if ($forum && $this->deleteAvatarFile($forum)) $stats['avatar file'] = 1;
        return array_filter($stats);
    }

    private function deleteForumUser(object $forum, int $uid): array
    {
        $db = self::FORUM_DB;
        $stats = [];

        // Reports dieses Users: Report-Flags an Posts/Topics zurücksetzen,
        // sofern dort kein anderer Report mehr offen ist.
        $reported = DB::table("$db.phpbb_reports as r")
            ->join("$db.phpbb_posts as p", 'p.post_id', '=', 'r.post_id')
            ->where('r.user_id', $uid)
            ->get(['p.post_id', 'p.topic_id']);
        $reportPosts = $reported->pluck('post_id')->unique()->values()->all();
        $reportTopics = $reported->pluck('topic_id')->unique()->values()->all();
        $stats['phpbb_reports'] = DB::table("$db.phpbb_reports")->where('user_id', $uid)->delete();
        if ($reportPosts) {
            $stillReported = DB::table("$db.phpbb_reports")->whereIn('post_id', $reportPosts)->pluck('post_id')->all();
            $clearPosts = array_values(array_diff($reportPosts, $stillReported));
            if ($clearPosts) DB::table("$db.phpbb_posts")->whereIn('post_id', $clearPosts)->update(['post_reported' => 0]);
            $keepTopics = DB::table("$db.phpbb_posts")->whereIn('topic_id', $reportTopics)
                ->where('post_reported', 1)->distinct()->pluck('topic_id')->all();
            $clearTopics = array_values(array_diff($reportTopics, $keepTopics));
            if ($clearTopics) DB::table("$db.phpbb_topics")->whereIn('topic_id', $clearTopics)->update(['topic_reported' => 0]);
        }

        // Beiträge behalten, dem Anonymous-User zuordnen
        DB::table("$db.phpbb_forums")->where('forum_last_poster_id', $uid)
            ->update(['forum_last_poster_id' => 1, 'forum_last_poster_name' => 'Deleted', 'forum_last_poster_colour' => '']);
        $stats['phpbb_posts (→ Deleted)'] = DB::table("$db.phpbb_posts")->where('poster_id', $uid)
            ->update(['poster_id' => 1, 'post_username' => 'Deleted']);
        $stats['phpbb_topics (→ Deleted)'] = DB::table("$db.phpbb_topics")->where('topic_poster', $uid)
            ->update(['topic_poster' => 1, 'topic_first_poster_name' => 'Deleted', 'topic_first_poster_colour' => '']);
        DB::table("$db.phpbb_topics")->where('topic_last_poster_id', $uid)
            ->update(['topic_last_poster_id' => 1, 'topic_last_poster_name' => 'Deleted', 'topic_last_poster_colour' => '']);
        DB::table("$db.phpbb_attachments")->where('poster_id', $uid)->update(['poster_id' => 1]);
        if ((int) $forum->user_posts > 0) {
            DB::table("$db.phpbb_users")->where('user_id', 1)->increment('user_posts', (int) $forum->user_posts);
        }

        foreach (self::USER_TABLES as $table) {
            if ($table === 'phpbb_reports') continue;
            $stats[$table] = DB::table("$db.$table")->where('user_id', $uid)->delete();
        }
        $stats['phpbb_sessions'] = DB::table("$db.phpbb_sessions")->where('session_user_id', $uid)->delete();

        if ($stats['phpbb_users'] > 0) {
            DB::update("UPDATE $db.phpbb_config SET config_value = config_value - 1 WHERE config_name = 'num_users'");
        }
        return $stats;
    }

    /** Hochgeladener Avatar: phpBB speichert ihn als <avatar_salt>_<user_id>.<ext>. */
    private function deleteAvatarFile(object $forum): bool
    {
        if ($forum->user_avatar_type !== 'avatar.driver.upload' || !$forum->user_avatar) return false;
        $cfg = DB::table(self::FORUM_DB . '.phpbb_config')
            ->whereIn('config_name', ['avatar_path', 'avatar_salt'])
            ->pluck('config_value', 'config_name');
        $ext = strtolower(pathinfo($forum->user_avatar, PATHINFO_EXTENSION));
        if (empty($cfg['avatar_path']) || empty($cfg['avatar_salt']) || !preg_match('/^[a-z0-9]+$/', $ext)) return false;

        // pthranking liegt im Forums-Root, base_path() ist also <forum>/pthranking
        $file = dirname(base_path()) . '/' . trim($cfg['avatar_path'], '/') . '/'
            . $cfg['avatar_salt'] . '_' . (int) $forum->user_id . '.' . $ext;
        return is_file($file) && @unlink($file);
    }
}
