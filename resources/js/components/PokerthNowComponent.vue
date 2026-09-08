<template>
    <div v-if="visible" class="pth-now">
        <hr />
        <h2>PokerTH right now</h2>

        <p class="pth-now__line pth-now__online">
            <span class="pth-now__dot" :class="{ 'pth-now__dot--stale': stale }"></span>
            <strong>{{ online }}</strong>&nbsp;{{ online === 1 ? 'player' : 'players' }} online
        </p>

        <template v-if="!stale">
            <p class="pth-now__line">{{ tables }} {{ tables === 1 ? 'table' : 'tables' }} running</p>
            <p class="pth-now__line">{{ waiting }} {{ waiting === 1 ? 'player' : 'players' }} waiting for games</p>
        </template>

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

    &__line {
        margin: .15em 0;
        font-size: larger;
    }

    &__online strong {
        font-weight: 700;
    }

    &__dot {
        display: inline-block;
        width: .7em;
        height: .7em;
        margin-right: .45em;
        border-radius: 50%;
        background: var(--pth-pot, #5fc35f);
    }

    &__dot--stale {
        background: var(--pth-text-dimmed, #888);
    }

    &__cta {
        margin: .5em 0 0;
        font-size: larger;

        a {
            color: var(--pth-gold, #ffd700);
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
