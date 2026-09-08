<template>
    <div v-if="visible" class="pth-now">
        <hr />
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
</template>

<script>
// Der Server schreibt den Heartbeat minuetlich, die Route cacht 15 s -
// oefter als einmal pro Minute abzufragen bringt nichts.
const POLL_MS = 60000

export default {
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
        // Vor dem ersten Laden nichts rendern (kein Layout-Sprung).
        // Server tot UND keine offenen Sessions mehr: Box ganz weglassen.
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
        onVisible() {
            if (!document.hidden) this.fetch()
        },
        fetch() {
            axios.get('/pthranking/live')
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
        color: var(--pth-text-dimmed, #5a6270);
    }

    // Statuspunkt-Ersatz: das User-Icon wird gruen, solange der Server lebt.
    &__ico--live {
        color: var(--pth-pot, #5fc35f);
    }

    &__cta {
        margin: .55em 0 0;
        font-size: larger;

        a {
            // Gleiches Gruen wie die "Play now!"-Zeile im Teaser-Button.
            color: #4ade80;
            font-weight: 700;
            text-decoration: none;

            &:hover {
                text-decoration: underline;
            }
        }
    }

    hr {
        margin-bottom: 1em;
    }
}
</style>
