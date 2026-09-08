# PokerTH server: live-stats heartbeat (`server_live_stats`)

**Audience:** an AI or developer working on the PokerTH **server** C++ codebase
(`pokerth` repo, `src/net/common/…`).
**Goal:** once a minute, write the current lobby snapshot (players online, tables,
players waiting) into one row of the ranking database so that pokerth.net can show
a "PokerTH right now" box without opening a protobuf client connection.

This mirrors the existing `server_run` / `server_session` logging that the server
already does. Follow the **same pattern, same code paths, same config gate, same
"fire and forget, never block the lobby" error handling**.

---

## 1. Why

pokerth.net wants a small sidebar widget:

```
PokerTH right now
● 42 players online
18 tables running
7 players waiting for games
Play now →
```

`players online` alone can be derived on the website from
`server_session WHERE disconnected_at IS NULL`, but **tables running** and
**players waiting** live only in the running server's memory. The cheapest,
most accurate source is the server itself writing a heartbeat row.

The website reads exactly one row and decides "fresh" vs "stale" from its
timestamp. No new server → website connection, no polling client, no protobuf.

---

## 2. Database

Same database and connection the server already uses for `server_run` /
`server_session` (ranking DB, `pokerth_ranking`).

### 2.1 Schema (create once)

```sql
CREATE TABLE `server_live_stats` (
  `id`               tinyint(3) unsigned NOT NULL,
  `run_id`           int(10) unsigned    DEFAULT NULL COMMENT 'server_run.run_id of the writing process',
  `players_online`   smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT 'established sessions: lobby + in games',
  `tables_running`   smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT 'open games in the lobby game list',
  `players_waiting`  smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT 'established sessions in the lobby, not seated at a game',
  `updated_at`       datetime            NOT NULL COMMENT 'server-local wall clock, same convention as server_session.connected_at',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

INSERT INTO `server_live_stats` (`id`, `updated_at`) VALUES (1, '1970-01-01 00:00:00');
```

The table holds **exactly one row, `id = 1`, forever**. Every write is an
`UPDATE` (or upsert) of that row. It never grows.

Whoever applies this schema change (probably the website maintainer) will do it
on the live DB; the server code only needs the table to exist.

### 2.2 The write

Once per minute, and once more on graceful shutdown (see §4):

```sql
INSERT INTO server_live_stats
    (id, run_id, players_online, tables_running, players_waiting, updated_at)
VALUES
    (1, :run_id, :players_online, :tables_running, :players_waiting, :now)
ON DUPLICATE KEY UPDATE
    run_id          = VALUES(run_id),
    players_online  = VALUES(players_online),
    tables_running  = VALUES(tables_running),
    players_waiting = VALUES(players_waiting),
    updated_at      = VALUES(updated_at);
```

`:run_id` is the `server_run.run_id` this process created at startup (the value
you already keep around for `server_session.run_id`). If for some reason it is
not available, write `NULL` — it is informational only.

---

## 3. The three numbers

Compute these from the lobby state you already have in `ServerLobbyThread`.
Names below are from PokerTH server ~2.1.x — **verify against the actual tree**,
they are pointers, not gospel.

| Column | Definition | Likely source |
|---|---|---|
| `players_online` | Count of **established** sessions, both those sitting in the lobby and those currently in a game. | `ServerStats::numberOfPlayersOnServer` (the value already tracked by `InternalUpdateStatData` / broadcast in `AnnounceMessage`). Equivalent to `m_sessionManager` established count + `m_gameSessionManager` established count. |
| `tables_running` | Number of open games in the lobby game list. | `ServerStats::numberOfGamesOpen`, i.e. `m_gameMap.size()`. |
| `players_waiting` | Established sessions **in the lobby that are not a member of any game**. | `m_sessionManager` established-session count (the lobby-only manager; sessions move to `m_gameSessionManager` when they join a game). Or `players_online - Σ(players in each ServerGame)`. |

Notes / decisions:

* **Guests count.** They are real concurrent players and the client's own
  "players on server" figure includes them. Keep them in all three numbers.
* **Established only.** Sessions still in `Init` / auth handshake must not be
  counted — match whatever `numberOfPlayersOnServer` already does.
* **`tables_running` = all open games**, not "games in a running betting round".
  The lobby list a player sees does not distinguish them, and a table that is
  filling up is still a table. If you would rather report only started games,
  filter by `ServerGame` game-state and say so in a code comment — the website
  just renders the number and the label stays "tables running".
* If `players_waiting` would go negative from a race in the subtraction form,
  clamp to 0.

Do **not** add new locking or a new traversal of every game for this. If the
numbers are already computed for `ServerStats` each stat-data tick, reuse them
verbatim. A value that is a few seconds stale is completely fine.

---

## 4. When to write

* **Every 60 s.** Reuse the existing periodic timer in `ServerLobbyThread` — the
  same one that drives `InternalUpdateStatData` / stat logging. If that timer
  fires more often than once a minute, gate the DB write with a
  `last_written + 60s` check so you do not hammer the DB thread.
* **Once on graceful shutdown**, writing `players_online = 0, tables_running = 0,
  players_waiting = 0` with a fresh `updated_at`, in the same shutdown path that
  sets `server_run.stopped_at`. This makes the website show "server offline"
  immediately instead of waiting for the row to age out.
* **On a hard crash / kill**, nothing is written — that is expected. The website
  treats a row older than a few minutes as stale (see §6) and falls back to
  "offline" on its own.

No need to write "on every change". Minutely is enough; the user confirmed this.

---

## 5. How to write it (code path)

Follow the `server_session` precedent exactly:

1. Add a method on `ServerDBThread` (`src/net/common/serverdbthread.{h,cpp}`),
   e.g.

   ```cpp
   void UpdateLiveStats(unsigned playersOnline,
                        unsigned tablesRunning,
                        unsigned playersWaiting);
   ```

   It enqueues a work item onto the DB thread's queue (same mechanism as the
   existing `server_run` / `server_session` writes) that runs the upsert from
   §2.2. Bind parameters; do not build SQL by string concatenation.

2. Call it from `ServerLobbyThread` in the stat-data / timer tick, throttled to
   60 s, and from the shutdown path.

3. **Error handling: identical to the other logging.** If the DB thread is not
   connected, or the query throws, swallow it (log at debug/warning level at
   most) and carry on. The heartbeat must never delay lobby processing, never
   throw into the lobby thread, never take a lock the lobby thread holds.

4. **Timestamp convention.** `updated_at` must be written in the **same time
   base as `server_session.connected_at`** (currently server-local wall clock,
   e.g. `boost::posix_time::second_clock::local_time()` formatted
   `YYYY-MM-DD HH:MM:SS`). Do **not** use MySQL `NOW()` / `UTC_TIMESTAMP()` here
   — the DB session time zone differs from the server's local time and the
   website compares `updated_at` against the same clock it uses for
   `server_session`.

---

## 6. Config gate

Gate the heartbeat on the **same config flag** that already enables
`server_run` / `server_session` logging (whatever key that is —
`DBServerLogSessions` or similar). If DB session logging is off, the heartbeat is
off too. No separate knob unless you want one; if you add one, default it to the
same value as the session-logging flag.

---

## 7. Verification

After deploying, on the ranking DB:

```sql
SELECT * FROM server_live_stats;      -- one row, id = 1
-- updated_at should advance by ~60 s each check while the server runs
-- players_online should track the client's own "players on server" number
-- and roughly match:
SELECT COUNT(*) FROM server_session
 WHERE run_id = (SELECT MAX(run_id) FROM server_run)
   AND disconnected_at IS NULL;
```

Sanity:

* `players_waiting <= players_online` always.
* `updated_at` never in the future, never more than ~90 s behind "now" while the
  process is alive.
* After `SIGTERM`, the row shows all zeros and a fresh `updated_at`, and
  `server_run.stopped_at` is set.

---

## 8. Out of scope

* No history / time series — one row, overwritten. (The website can sample it
  later if it ever wants a graph.)
* No per-game or per-player detail — that is what `server_session` and the
  spectator protocol are for.
* No new network endpoint or message type on the server.
* Website side (read route, caching, the Vue component, staleness handling) is
  handled separately in the `pthranking` app and does not concern the server.

---

## 9. Contract summary (what the website relies on)

1. Table `server_live_stats`, one row `id = 1`.
2. `updated_at` in server-local wall-clock time, same as `server_session`.
3. Row is refreshed at least once a minute while the server is up.
4. On graceful shutdown the row is zeroed with a fresh `updated_at`.
5. A stale row (no update for a few minutes) means "server down / unknown" and
   the website will fall back or hide the widget.
