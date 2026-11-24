<?php

/**
 * Seed presets for `php artisan ranking:track-ring`.
 *
 * Each preset is a list of seed accounts (nicknames OR numeric player_id) from
 * which the command expands the ring of a multi-account / ban-evasion operator
 * and highlights new accounts.
 *
 * Real player names do NOT belong in this (public) repo. The actual presets
 * therefore live in the gitignored file config/rings.local.php, e.g.:
 *
 *     <?php
 *     return [
 *         'default' => ['NickA', 'NickB', 12345],
 *     ];
 *
 * A ring can also be passed ad hoc via --seed, with no preset at all.
 */
$local = __DIR__ . '/rings.local.php';

return is_file($local) ? (array) require $local : [];
