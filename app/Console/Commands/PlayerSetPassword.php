<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Support\RankingPassword;
use Illuminate\Support\Facades\DB;

/**
 * Sets a player's password in both places it lives:
 *
 *  - Forum (phpBB, db "pokerth"): phpbb_users.user_password as argon2id hash,
 *    exactly like phpBB's passwords.driver.argon2id (see
 *    config/default/container/services_password.yml), plus user_passchg and
 *    the "remember me" login keys reset, like ACP -> Users -> Overview does.
 *  - Ranking db (player.password): AES_ENCRYPT(password, APP_SALT) bytes, computed
 *    in PHP by App\Support\RankingPassword, which the
 *    game server and game clients use. Same as the forum listener
 *    (ext/inquies/pokerth/event/listener.php afterRegDetails).
 *
 * Both updates run in one transaction (same MariaDB connection, InnoDB).
 */
class PlayerSetPassword extends Command
{
    protected $signature = 'player:set-password
        {username : Exact player name (case-sensitive, as in forum and ranking db)}
        {password? : New password; omit it to be prompted (keeps it out of shell history)}
        {--force : Do not ask for confirmation}';

    protected $description = 'Set a player\'s password in the forum (phpBB) and the ranking db';

    /** Forum database; hardcoded like everywhere else in pthranking. */
    private const FORUM_DB = 'pokerth';

    /** Same rules as the forum listener (inquies\pokerth\event\listener::password_error). */
    private const MAX_PASSWORD_LENGTH = 50;

    public function handle(): int
    {
        $username = (string) $this->argument('username');
        $password = $this->argument('password');

        if ($password === null) {
            $password = (string) $this->secret('New password');
            if ($password !== (string) $this->secret('Repeat new password')) {
                $this->error('Password repeat mismatch.');
                return self::FAILURE;
            }
        }

        if ($err = $this->passwordError($password)) {
            $this->error($err);
            return self::FAILURE;
        }

        $forumUsers = DB::table(self::FORUM_DB . '.phpbb_users')
            ->select('user_id', 'username', 'user_email', 'user_type')
            ->where('username', $username)
            ->get();
        $players = DB::table('player')
            ->select('player_id', 'username', 'email', 'active', 'blocked')
            ->where('username', $username)
            ->get();

        if ($forumUsers->count() > 1 || $players->count() > 1) {
            $this->error("Ambiguous: {$forumUsers->count()} forum users / {$players->count()} ranking players named '{$username}'.");
            return self::FAILURE;
        }

        $forumUser = $forumUsers->first();
        $player = $players->first();

        if (!$forumUser && !$player) {
            $this->error("No forum user and no ranking player named '{$username}' (names are case-sensitive).");
            return self::FAILURE;
        }

        $this->table(['', 'id', 'username', 'email', 'state'], [
            ['Forum', $forumUser->user_id ?? '-', $forumUser->username ?? 'NOT FOUND', $forumUser->user_email ?? '-',
                $forumUser ? 'user_type=' . $forumUser->user_type : '-'],
            ['Ranking', $player->player_id ?? '-', $player->username ?? 'NOT FOUND', $player->email ?? '-',
                $player ? "active={$player->active} blocked={$player->blocked}" : '-'],
        ]);

        if (!$forumUser || !$player) {
            $this->warn('Account exists only on one side – only that side will be updated.');
        }
        if ($forumUser && $player && strcasecmp($forumUser->user_email, $player->email) !== 0) {
            $this->warn('Email addresses differ between forum and ranking db.');
        }

        if (!$this->option('force') && !$this->confirm('Set the new password?', true)) {
            $this->line('Aborted.');
            return self::FAILURE;
        }

        DB::transaction(function () use ($forumUser, $player, $password) {
            if ($forumUser) {
                DB::table(self::FORUM_DB . '.phpbb_users')
                    ->where('user_id', $forumUser->user_id)
                    ->update([
                        'user_password' => $this->phpbbHash($password),
                        'user_passchg' => time(),
                        'user_login_attempts' => 0,
                    ]);
                // phpBB $user->reset_login_keys(): invalidate "remember me" logins
                DB::table(self::FORUM_DB . '.phpbb_sessions_keys')
                    ->where('user_id', $forumUser->user_id)
                    ->delete();
            }
            if ($player) {
                // Encrypted in PHP: APP_SALT and the password never go into SQL text.
                DB::update(
                    'UPDATE player SET password = ? WHERE player_id = ?',
                    [RankingPassword::encrypt($password), $player->player_id]
                );
            }
        });

        // Verify what was written
        $ok = true;
        if ($forumUser) {
            $hash = DB::table(self::FORUM_DB . '.phpbb_users')->where('user_id', $forumUser->user_id)->value('user_password');
            $forumOk = password_verify($password, (string) $hash);
            $ok = $ok && $forumOk;
            $forumOk ? $this->info('Forum password updated.') : $this->error('Forum password verification FAILED.');
        }
        if ($player) {
            $blob = DB::table('player')->where('player_id', $player->player_id)->value('password');
            $rankingOk = RankingPassword::check($password, $blob);
            $ok = $ok && $rankingOk;
            $rankingOk ? $this->info('Ranking db password updated.') : $this->error('Ranking db password verification FAILED.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Same charset/length rules as the forum, plus phpBB's minimum length.
     */
    private function passwordError(string $password): ?string
    {
        $min = (int) (DB::table(self::FORUM_DB . '.phpbb_config')
            ->where('config_name', 'min_pass_chars')
            ->value('config_value') ?? 6);

        if (!preg_match('/^[A-Za-z0-9.,_-]+$/', $password)) {
            return 'Password may only contain letters, digits and . , _ -';
        }
        if (strlen($password) < $min) {
            return "Password is too short (minimum {$min} characters).";
        }
        if (strlen($password) > self::MAX_PASSWORD_LENGTH) {
            return 'Password is too long (maximum ' . self::MAX_PASSWORD_LENGTH . ' characters).';
        }
        return null;
    }

    /**
     * Mirrors phpbb\passwords\driver\argon2id with the cost parameters from
     * services_password.yml (memory 65536, time 4, threads 2).
     */
    private function phpbbHash(string $password): string
    {
        $memory = max(65536, defined('PASSWORD_ARGON2_DEFAULT_MEMORY_COST') ? PASSWORD_ARGON2_DEFAULT_MEMORY_COST : 65536);
        $time = max(4, defined('PASSWORD_ARGON2_DEFAULT_TIME_COST') ? PASSWORD_ARGON2_DEFAULT_TIME_COST : 4);
        $threads = (defined('PASSWORD_ARGON2_PROVIDER') && PASSWORD_ARGON2_PROVIDER == 'sodium')
            ? 1
            : max(2, defined('PASSWORD_ARGON2_DEFAULT_THREADS') ? PASSWORD_ARGON2_DEFAULT_THREADS : 1);

        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => $memory,
            'time_cost' => $time,
            'threads' => $threads,
        ]);
    }
}
