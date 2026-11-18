<template>
    <admin-panel
        title="Game-Server messages"
        :subtitle="subtitle"
        :count="rows.length"
        :loading="loading"
        @refresh="load"
    >
        <template #toolbar>
            <el-input
                v-model="q"
                class="admin-toolbar__search"
                placeholder="Search text or exact nick"
                :prefix-icon="Search"
                clearable
                @change="load"
            />
            <el-select
                v-model="levels"
                class="srvmsg__levels"
                multiple
                collapse-tags
                placeholder="All levels"
                @change="load"
            >
                <el-option v-for="l in allLevels" :key="l" :label="l" :value="l" />
            </el-select>
            <el-select v-model="run" class="admin-toolbar__select" placeholder="All runs" clearable @change="onRunChange">
                <el-option v-for="r in runs" :key="r.run_id" :label="runLabel(r)" :value="r.run_id" />
            </el-select>
            <el-date-picker
                v-model="range"
                class="srvmsg__range"
                type="datetimerange"
                start-placeholder="From"
                end-placeholder="To"
                value-format="YYYY-MM-DD HH:mm:ss"
                format="YYYY-MM-DD HH:mm"
                :default-time="defaultTime"
                @change="load"
            />
            <el-select v-model="limit" class="admin-toolbar__select" @change="load">
                <el-option v-for="n in limits" :key="n" :label="`Last ${n} lines`" :value="n" />
            </el-select>

            <el-popover placement="bottom-end" :width="320" trigger="click">
                <template #reference>
                    <el-button :icon="Download">Download</el-button>
                </template>
                <div class="srvmsg__dl">
                    <p class="admin-muted">
                        Original <code>server_messages.log</code> format, one window of up to {{ maxHours }} h.
                        Level, run, player, IP and text filters apply; the view's From/To does not.
                    </p>
                    <el-date-picker
                        v-model="dlStart"
                        type="datetime"
                        placeholder="Start (empty = most recent)"
                        value-format="YYYY-MM-DD HH:mm:ss"
                        format="YYYY-MM-DD HH:mm"
                        class="srvmsg__dl-field"
                    />
                    <el-select v-model="dlHours" class="srvmsg__dl-field">
                        <el-option v-for="h in hourOptions" :key="h" :label="`${h} hours`" :value="h" />
                    </el-select>
                    <div class="srvmsg__dl-window admin-muted">{{ dlWindowText }}</div>
                    <div class="srvmsg__dl-nav">
                        <el-button size="small" @click="shiftWindow(-1)">‹ Previous</el-button>
                        <el-button size="small" :disabled="!dlStart" @click="shiftWindow(1)">Next ›</el-button>
                        <el-button type="primary" size="small" tag="a" :href="downloadUrl">Download</el-button>
                    </div>
                </div>
            </el-popover>
        </template>

        <template v-if="chips.length" #bulk>
            <span class="admin-muted">Filtered by</span>
            <el-tag v-for="c in chips" :key="c.key" closable disable-transitions @close="clearFilter(c.key)">
                {{ c.label }}
            </el-tag>
        </template>

        <div ref="box" v-loading="loading" class="lobbychat srvmsg">
            <div v-if="more" class="srvmsg__older">
                <el-button size="small" :loading="loadingOlder" @click="loadOlder">Load older</el-button>
            </div>
            <div v-if="!rows.length" class="lobbychat__empty admin-muted">{{ loading ? 'Loading…' : 'No log entries.' }}</div>
            <div v-for="row in rows" :key="row.id" class="lobbychat__line srvmsg__line" :class="'is-' + row.level.toLowerCase()">
                <span class="lobbychat__time">{{ row.time }}</span>
                <span class="srvmsg__level">{{ row.level }}</span>
                <span class="lobbychat__msg">{{ row.msg }}</span>
                <span class="srvmsg__meta">
                    <a v-if="row.name" href="#" :title="row.pid ? 'Filter by player id ' + row.pid : 'Search this nick'" @click.prevent="filterPlayer(row)">👤 {{ row.name }}</a>
                    <a v-if="row.ip" href="#" title="Filter by this IP" @click.prevent="setFilter('ip', row.ip)">🌍 {{ row.ip }}</a>
                    <a v-if="row.game !== null && row.run" href="#" title="Filter by this game (within the run)" @click.prevent="filterInRun(row, 'game')">🎲 game {{ row.game }}</a>
                    <a v-if="row.session !== null && row.run" href="#" title="Filter by this session (within the run)" @click.prevent="filterInRun(row, 'session')">🔌 #{{ row.session }}</a>
                </span>
            </div>
        </div>
    </admin-panel>
</template>

<script>
import { Download, Search } from '@element-plus/icons-vue'
import AdminPanel from './AdminPanel.vue'
import { API_BASE, apiGet, notice, reportError } from '../admin/adminUtils.js'

const POLL_MS = 30000
const p2 = (n) => String(n).padStart(2, '0')

/** Wanduhrzeit "YYYY-MM-DD HH:mm:ss" zonenlos verschieben (Rechnung in UTC). */
function shiftWallClock(value, hours) {
    const d = new Date(value.replace(' ', 'T') + 'Z')
    d.setUTCHours(d.getUTCHours() + hours)
    return `${d.getUTCFullYear()}-${p2(d.getUTCMonth() + 1)}-${p2(d.getUTCDate())} ${p2(d.getUTCHours())}:${p2(d.getUTCMinutes())}:${p2(d.getUTCSeconds())}`
}

export default {
    name: 'ServerMessages',
    components: { AdminPanel },
    data() {
        return {
            rows: [],
            runs: [],
            more: false,
            loading: false,
            loadingOlder: false,
            latest: null,
            serverNow: null,
            q: '',
            levels: [],
            allLevels: ['ERR', 'MSG', 'OUT'],
            run: null,
            range: null,
            filters: { pid: null, ip: null, game: null, session: null },
            limit: 1000,
            limits: [500, 1000, 2000, 5000],
            defaultTime: [new Date(2000, 0, 1, 0, 0, 0), new Date(2000, 0, 1, 23, 59, 59)],
            maxHours: 48,
            dlStart: null,
            dlHours: 48,
            Download,
            Search,
        }
    },
    computed: {
        subtitle() {
            return 'game-server local time (CET/CEST), copy of server_messages.log'
        },
        hourOptions() {
            return [1, 6, 12, 24, 48].filter((h) => h <= this.maxHours)
        },
        /** Filter ohne Zeitraum – gemeinsam für Ansicht und Download. */
        baseParams() {
            const p = { q: this.q || undefined, level: this.levels.length ? this.levels : undefined }
            if (this.run) {
                p.run = this.run
                if (this.filters.game !== null) p.game = this.filters.game
                if (this.filters.session !== null) p.session = this.filters.session
            }
            if (this.filters.pid !== null) p.pid = this.filters.pid
            if (this.filters.ip) p.ip = this.filters.ip
            return p
        },
        viewParams() {
            const p = { ...this.baseParams, limit: this.limit }
            if (this.range?.[0]) p.from = this.range[0]
            if (this.range?.[1]) p.to = this.range[1]
            return p
        },
        downloadUrl() {
            const sp = new URLSearchParams()
            for (const [k, v] of Object.entries(this.baseParams)) {
                if (v === undefined || v === null) continue
                if (Array.isArray(v)) v.forEach((x) => sp.append(`${k}[]`, x))
                else sp.append(k, v)
            }
            if (this.dlStart) sp.append('start', this.dlStart)
            sp.append('hours', this.dlHours)
            return `${API_BASE}/servermessages/download?${sp}`
        },
        dlWindowText() {
            if (!this.dlStart) return `Window: the last ${this.dlHours} hours`
            return `Window: ${this.dlStart.slice(0, 16)} → ${shiftWallClock(this.dlStart, this.dlHours).slice(0, 16)}`
        },
        chips() {
            const f = this.filters
            const out = []
            if (f.pid !== null) out.push({ key: 'pid', label: `player id ${f.pid}` })
            if (f.ip) out.push({ key: 'ip', label: `IP ${f.ip}` })
            if (f.game !== null) out.push({ key: 'game', label: `run #${this.run} game ${f.game}` })
            if (f.session !== null) out.push({ key: 'session', label: `run #${this.run} session #${f.session}` })
            return out
        },
    },
    mounted() {
        this.load()
        this.timer = setInterval(() => {
            if (!document.hidden) this.poll()
        }, POLL_MS)
    },
    beforeUnmount() {
        clearInterval(this.timer)
    },
    methods: {
        runLabel(r) {
            const end = r.stopped_at ? ' – ' + r.stopped_at.slice(5, 16) : ' (running)'
            return `#${r.run_id} ${r.started_at.slice(0, 16)}${end}`
        },
        onRunChange() {
            this.filters.game = null
            this.filters.session = null
            this.load()
        },
        setFilter(key, value) {
            this.filters[key] = value
            this.load()
        },
        clearFilter(key) {
            this.filters[key] = null
            this.load()
        },
        filterPlayer(row) {
            if (row.pid) {
                this.setFilter('pid', row.pid)
            } else {
                this.q = row.name
                this.load()
            }
        },
        filterInRun(row, key) {
            if (this.run !== row.run) {
                this.run = row.run
                this.filters.game = null
                this.filters.session = null
            }
            this.setFilter(key, row[key])
        },
        /** Download-Fenster um seine eigene Länge verschieben. */
        shiftWindow(dir) {
            let start = this.dlStart
            if (!start) {
                // "Zuletzt" endet jetzt - Serveruhr als Anker, nicht die Browserzeit.
                if (!this.serverNow) return
                start = shiftWallClock(this.serverNow, -this.dlHours)
            }
            this.dlStart = shiftWallClock(start, dir * this.dlHours)
        },
        isAtBottom() {
            const box = this.$refs.box
            return !box || box.scrollHeight - box.scrollTop - box.clientHeight < 40
        },
        scrollToBottom() {
            this.$nextTick(() => {
                const box = this.$refs.box
                if (box) box.scrollTop = box.scrollHeight
            })
        },
        apply(data) {
            this.rows = data.list
            this.more = data.more
            this.runs = data.runs || this.runs
            this.latest = data.latest
            this.serverNow = data.server_now || this.serverNow
            if (data.download_hours) {
                this.maxHours = data.download_hours
                if (this.dlHours > this.maxHours) this.dlHours = this.maxHours
            }
        },
        async load() {
            this.loading = true
            try {
                const data = await apiGet('/servermessages', this.viewParams)
                if (data.success) {
                    this.apply(data)
                    this.scrollToBottom()
                } else {
                    this.rows = []
                    notice(data.msg || 'Could not load the server messages.', 'warning')
                }
            } catch (err) {
                reportError(err, 'Could not load the server messages.')
            } finally {
                this.loading = false
            }
        },
        async loadOlder() {
            if (!this.rows.length) return
            this.loadingOlder = true
            const box = this.$refs.box
            const fromBottom = box ? box.scrollHeight - box.scrollTop : 0
            try {
                const data = await apiGet('/servermessages', { ...this.viewParams, before: this.rows[0].id })
                if (data.success) {
                    this.rows = [...data.list, ...this.rows]
                    this.more = data.more
                    // Leseposition halten statt nach oben zu springen.
                    this.$nextTick(() => {
                        if (box) box.scrollTop = box.scrollHeight - fromBottom
                    })
                }
            } catch (err) {
                reportError(err, 'Could not load older entries.')
            } finally {
                this.loadingOlder = false
            }
        },
        /**
         * Stiller Reload wie im Lobby-Chat - nur wenn man unten steht, kein
         * "bis"-Zeitpunkt gesetzt ist und nichts älteres nachgeladen wurde.
         */
        async poll() {
            if (this.loading || this.loadingOlder || this.range?.[1] || !this.isAtBottom()) return
            if (this.rows.length > this.limit) return
            try {
                const data = await apiGet('/servermessages', this.viewParams)
                if (!data.success || data.latest === this.latest) return
                this.apply(data)
                this.scrollToBottom()
            } catch (err) {
                console.warn('ServerMessages: refresh failed', err)
            }
        },
    },
}
</script>
