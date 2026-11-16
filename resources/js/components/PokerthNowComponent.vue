<template>
    <!-- Kopfleisten-Variante fuer die Internals-Seiten: nur Statuspunkt,
         Icons und Zahlen. Die Bedeutung steht im Tooltip (title), damit die
         Leiste auf allen Admin-Seiten schmal bleibt. -->
    <div v-if="header" class="pth-now-bar" :class="{ 'pth-now-bar--off': !live }"
         role="status" :aria-label="statusLabel">
        <span class="pth-now-bar__dot" :class="{ 'pth-now-bar__dot--live': live }" :title="statusLabel" />

        <span class="pth-now-bar__item" :title="live ? plural(online, 'player', 'players') + ' online' : statusLabel">
            <svg class="pth-now-bar__ico" viewBox="0 0 24 24" aria-hidden="true"
                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                <circle cx="12" cy="7" r="4" />
            </svg>
            <strong>{{ live ? online : '–' }}</strong>
        </span>

        <span class="pth-now-bar__item" :title="live ? plural(tables, 'table', 'tables') + ' running' : statusLabel">
            <svg class="pth-now-bar__ico" viewBox="0 0 24 24" aria-hidden="true"
                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="7" height="7" rx="1" />
                <rect x="14" y="3" width="7" height="7" rx="1" />
                <rect x="14" y="14" width="7" height="7" rx="1" />
                <rect x="3" y="14" width="7" height="7" rx="1" />
            </svg>
            <strong>{{ live ? tables : '–' }}</strong>
        </span>

        <span class="pth-now-bar__item" :title="live ? plural(waiting, 'player', 'players') + ' waiting for games' : statusLabel">
            <svg class="pth-now-bar__ico" viewBox="0 0 24 24" aria-hidden="true"
                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="9" />
                <polyline points="12 7 12 12 16 14" />
            </svg>
            <strong>{{ live ? waiting : '–' }}</strong>
        </span>

        <!-- Tageszahl kommt aus der DB, nicht vom Heartbeat - auch offline gueltig. -->
        <span class="pth-now-bar__item pth-now-bar__item--stat"
              :title="loaded ? plural(today, 'game', 'games') + ' today' : statusLabel">
            <svg class="pth-now-bar__ico" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 3s7 6.5 7 11a3.5 3.5 0 0 1-6.1 2.4c.2 2 1 3.4 2.6 4.6H8.5c1.6-1.2 2.4-2.6 2.6-4.6A3.5 3.5 0 0 1 5 14c0-4.5 7-11 7-11z" />
            </svg>
            <strong>{{ loaded ? today : '–' }}</strong>
        </span>
    </div>

    <div v-else-if="visible">
        <hr />
        <div class="pth-now">
            <h2>Game-Server Status</h2>

            <ul class="pth-now__list">
                <li>
                    <svg class="pth-now__ico" :class="{ 'pth-now__ico--live': !stale }" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                        <circle cx="12" cy="7" r="4" />
                    </svg>
                    <span><strong>{{ online }}</strong> {{ online === 1 ? 'player' : 'players' }} online</span>
                </li>

                <li v-if="!stale">
                    <svg class="pth-now__ico" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="7" height="7" rx="1" />
                        <rect x="14" y="3" width="7" height="7" rx="1" />
                        <rect x="14" y="14" width="7" height="7" rx="1" />
                        <rect x="3" y="14" width="7" height="7" rx="1" />
                    </svg>
                    <span><strong>{{ tables }}</strong> {{ tables === 1 ? 'table' : 'tables' }} running</span>
                </li>

                <li v-if="!stale">
                    <svg class="pth-now__ico" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="9" />
                        <polyline points="12 7 12 12 16 14" />
                    </svg>
                    <span><strong>{{ waiting }}</strong> {{ waiting === 1 ? 'player' : 'players' }} waiting for games</span>
                </li>

                <li class="pth-now__stat">
                    <svg class="pth-now__ico" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 3s7 6.5 7 11a3.5 3.5 0 0 1-6.1 2.4c.2 2 1 3.4 2.6 4.6H8.5c1.6-1.2 2.4-2.6 2.6-4.6A3.5 3.5 0 0 1 5 14c0-4.5 7-11 7-11z" />
                    </svg>
                    <span><strong>{{ today }}</strong> {{ today === 1 ? 'game' : 'games' }} today</span>
                </li>
            </ul>

            <p class="pth-now__cta">
                <a href="https://webclient.pokerth.net" target="_blank" rel="noopener">Play now &rarr;</a>
            </p>
        </div>
    </div>
</template>

<script>
// Der Server schreibt den Heartbeat minuetlich, die Route cacht 15 s -
// oefter als einmal pro Minute abzufragen bringt nichts.
const POLL_MS = 60000

export default {
    props: {
        // "sidebar" (Default): Box mit Ueberschrift fuer die phpBB-Seitenleiste.
        // "header": Icon-Leiste fuer die Kopfzeile der Internals-Seiten.
        variant: { type: String, default: 'sidebar' },
    },
    data() {
        return {
            loaded: false,
            online: 0,
            tables: 0,
            waiting: 0,
            today: 0,
            stale: true,
            timer: null,
        }
    },
    computed: {
        header() {
            return this.variant === 'header'
        },
        // Livewerte sind erst nach dem ersten erfolgreichen Poll aussagekraeftig.
        live() {
            return this.loaded && !this.stale
        },
        statusLabel() {
            if (!this.loaded) return 'Game server: checking …'
            return this.stale ? 'Game server: no heartbeat' : 'Game server: live'
        },
        // Vor dem ersten Laden nichts rendern (kein Layout-Sprung).
        // Server tot UND keine offenen Sessions mehr: Box ganz weglassen.
        //
        // Gilt nur fuer die Sidebar: in der Admin-Kopfleiste ist "Server ist
        // tot" selbst eine Information, die Leiste wird dann grau statt zu
        // verschwinden.
        visible() {
            if (!this.loaded) return false
            return !this.stale || this.online > 0
        },
    },
    mounted() {
        this.fetch()
        this.timer = setInterval(() => {
            if (!document.hidden) this.fetch()
        }, POLL_MS)
        document.addEventListener('visibilitychange', this.onVisible)
    },
    beforeUnmount() {
        clearInterval(this.timer)
        document.removeEventListener('visibilitychange', this.onVisible)
    },
    methods: {
        plural(n, one, many) {
            return `${n} ${n === 1 ? one : many}`
        },
        onVisible() {
            if (!document.hidden) this.fetch()
        },
        fetch() {
            // Timeout, damit ein haengender Request die Leiste nicht dauerhaft
            // im 'Checking'-Zustand stehen laesst.
            axios.get('/pthranking/live', { timeout: 10000 })
                .then(res => {
                    const d = res.data
                    this.online = d.online ?? 0
                    this.tables = d.tables ?? 0
                    this.waiting = d.waiting ?? 0
                    this.today = d.today ?? 0
                    this.stale = !!d.stale
                    this.loaded = true
                })
                .catch(err => {
                    console.log(err)
                    // Netzfehler beim ersten Versuch: Box aus. Spaeter: letzten
                    // bekannten Stand stehen lassen, der naechste Poll heilt.
                    if (!this.loaded) {
                        this.loaded = true
                        this.stale = true
                    }
                })
        },
    },
}
</script>

<style lang="scss" scoped>
.pth-now {
    width: 86%;
    margin: 0 7%;
    text-align: left;

    // Sidebar-Kontext (phpBB + Element Plus): Kind-Elemente sollen keine
    // eigenen Hintergrundkaesten bekommen - gleiche Absicherung wie in
    // ChampionOfDayComponent.
    * {
        background-color: transparent !important;
    }

    h2 {
        text-align: left;
        margin: 0 0 .4em;
    }

    &__list {
        list-style: none;
        margin: 0;
        padding: 0;

        li {
            display: flex;
            align-items: center;
            gap: .5em;
            margin: .25em 0;
            font-size: larger;
            color: var(--pth-text-secondary, #838b98);
        }

        strong {
            font-weight: 700;
            color: var(--pth-text, #ccc);
        }
    }

    // Tagesstatistik, kein Livewert - etwas abgesetzt.
    &__stat {
        margin-top: .4em !important;
    }

    &__ico {
        width: 1.05em;
        height: 1.05em;
        flex-shrink: 0;
        color: var(--pth-icon, #5a6270);
    }

    // Statuspunkt-Ersatz: das User-Icon wird gruen, solange der Server lebt.
    &__ico--live {
        color: var(--pth-live, #4ade80);
    }

    &__cta {
        margin: .55em 0 0;
        font-size: larger;

        a {
            // "Go"-Gruen, theme-abhaengig (colors.css) - im Light Theme
            // gedeckter, damit es nicht schrill wirkt.
            color: var(--pth-live, #4ade80);
            font-weight: 700;
            text-decoration: none;

            &:hover {
                text-decoration: underline;
            }
        }
    }
}

/* <hr> sitzt ausserhalb von .pth-now (also volle Seitenleistenbreite, wie die
   anderen Trennlinien) - nicht in den 86%-Textblock einruecken. */
hr {
    margin-bottom: 1em;
}

/* ── Kopfleisten-Variante (Internals) ──────────────────────────────
   Sitzt in .internals-actions neben dem Theme-Schalter. */
.pth-now-bar {
    display: inline-flex;
    align-items: center;
    gap: .9rem;
    padding: .3rem .8rem;
    border: 1px solid var(--pth-border);
    border-radius: 999px;
    font-size: .85rem;
    line-height: 1;
    color: var(--pth-text-muted);
    white-space: nowrap;
    cursor: default;

    &__dot {
        width: .55rem;
        height: .55rem;
        border-radius: 50%;
        background-color: var(--pth-text-muted);

        // Nur solange der Server lebt: gruen und pulsierend.
        &--live {
            background-color: var(--pth-live);
            animation: pth-now-pulse 2s ease-in-out infinite;
        }
    }

    &__item {
        display: inline-flex;
        align-items: center;
        gap: .3rem;

        strong {
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            color: var(--pth-text);
        }
    }

    // Tageszahl ist kein Livewert - per Trennstrich absetzen.
    &__item--stat {
        padding-left: .9rem;
        border-left: 1px solid var(--pth-border);
    }

    &__ico {
        width: 1rem;
        height: 1rem;
        flex-shrink: 0;
        color: var(--pth-icon);
    }

}

// Kein Heartbeat: Livewerte ausgegraut ("–"), Tageszahl bleibt normal.
.pth-now-bar--off .pth-now-bar__item:not(.pth-now-bar__item--stat) strong {
    color: var(--pth-text-muted);
}

@keyframes pth-now-pulse {
    50% { opacity: .35; }
}

@media (prefers-reduced-motion: reduce) {
    .pth-now-bar__dot--live {
        animation: none;
    }
}

@media (max-width: 768px) {
    .pth-now-bar {
        gap: .6rem;
        padding: .25rem .6rem;

        &__item--stat {
            padding-left: .6rem;
        }
    }
}
</style>
