<template>
    <admin-panel
        title="Game-Server Log"
        :subtitle="subtitle"
        :loading="loading"
        @refresh="load"
    >
        <template #toolbar>
            <el-select v-model="days" class="admin-toolbar__select" @change="load">
                <el-option v-for="opt in dayOptions" :key="opt" :label="`Last ${opt} days`" :value="opt" />
            </el-select>
        </template>

        <div v-if="data && data.recent_hourly" class="serverlog-panel">
            <div class="serverlog-panel__head">
                <h4>Recent activity (last 48h)</h4>
                <span v-if="!data.enough_data" class="serverlog-panel__note">{{ earlyDataNote }}</span>
            </div>
            <div class="serverlog-chart">
                <bar-chart-component :chart-data="recentHourlyChartData" :options="barOptionsCompact" />
            </div>
        </div>

        <div v-if="!loading && data && !data.enough_data && !data.recent_hourly" class="serverlog-empty">
            No lobby activity has been logged yet.
        </div>

        <template v-else-if="data && data.enough_data">
            <p v-if="data.partial" class="serverlog-panel__note serverlog-panel">
                Early data - only {{ data.window.days }} full day{{ data.window.days === 1 ? '' : 's' }} recorded so
                far, trends below will stabilize as more days accumulate.
            </p>
            <div class="serverlog-tiles">
                <div v-for="tile in tiles" :key="tile.key" class="serverlog-tile">
                    <div class="serverlog-tile__key">{{ tile.key }}</div>
                    <div class="serverlog-tile__value" :class="`tone-${tile.tone}`">{{ tile.value }}</div>
                    <div class="serverlog-tile__sub">{{ tile.sub }}</div>
                </div>
            </div>

            <div class="serverlog-panel">
                <div class="serverlog-panel__head">
                    <h4>Daily active registered accounts</h4>
                    <span class="serverlog-panel__note">{{ activeTrendNote }}</span>
                </div>
                <div class="serverlog-chart serverlog-chart--tall">
                    <line-chart-component
                        :chart-data="activeChartData"
                        :options="lineOptions"
                        :plugins="[valueLabelsPlugin]"
                    />
                </div>
            </div>

            <div class="serverlog-panel">
                <div class="serverlog-panel__head">
                    <h4>New accounts per day</h4>
                    <span class="serverlog-panel__note">{{ newRegNote }}</span>
                </div>
                <div class="serverlog-chart serverlog-chart--tall">
                    <bar-chart-component
                        :chart-data="newRegChartData"
                        :options="barOptions"
                        :plugins="[valueLabelsPlugin, weekMeansPlugin]"
                    />
                </div>
            </div>

            <div class="serverlog-grid-2">
                <div class="serverlog-panel">
                    <div class="serverlog-panel__head">
                        <h4>Return rate of new accounts</h4>
                    </div>
                    <div class="serverlog-retention">
                        <div v-for="row in retentionRows" :key="row.label" class="serverlog-retention__row">
                            <span class="serverlog-retention__label">{{ row.label }}</span>
                            <el-progress
                                class="serverlog-retention__bar"
                                :percentage="row.pct"
                                :stroke-width="14"
                                :show-text="false"
                                :color="progressColor"
                            />
                            <span class="serverlog-retention__value">{{ row.pct }}% ({{ row.back }}/{{ row.total }})</span>
                        </div>
                    </div>
                    <p class="serverlog-panel__note">
                        Of {{ data.new_total }} registrations in this window, {{ data.new_seen }} ever logged in
                        ({{ seenPct }}%).
                    </p>
                </div>

                <div class="serverlog-panel">
                    <div class="serverlog-panel__head">
                        <h4>Active days of newcomers</h4>
                    </div>
                    <div class="serverlog-chart">
                        <bar-chart-component
                            :chart-data="histogramChartData"
                            :options="histogramOptions"
                            :plugins="[valueLabelsPlugin]"
                        />
                    </div>
                    <p class="serverlog-panel__note">
                        {{ oneDayPct }}% of newcomers were active on exactly one day (avg
                        {{ data.histogram.newcomer_avg_days }} days; established players avg
                        {{ data.established.avg_active_days }} days).
                    </p>
                </div>
            </div>

            <div class="serverlog-panel">
                <div class="serverlog-panel__head">
                    <h4>Time of day: the gap between {{ nightWindow }}</h4>
                    <span class="serverlog-panel__note">{{ nightSubNote }}</span>
                </div>
                <div class="serverlog-grid-2">
                    <div>
                        <p class="serverlog-panel__note">Logins per hour (daily mean)</p>
                        <div class="serverlog-chart">
                            <bar-chart-component
                                :chart-data="loginsHourChartData"
                                :options="loginsHourOptions"
                                :plugins="[nightBandPlugin]"
                            />
                        </div>
                    </div>
                    <div>
                        <p class="serverlog-panel__note">New-account share vs. traffic (1.0 = proportional)</p>
                        <div class="serverlog-chart">
                            <bar-chart-component
                                :chart-data="indexHourChartData"
                                :options="indexHourOptions"
                                :plugins="[nightBandPlugin]"
                            />
                        </div>
                    </div>
                </div>
                <p class="serverlog-panel__note">{{ nightNote }}</p>
                <p v-if="gapNote" class="serverlog-panel__note">{{ gapNote }}</p>
            </div>

            <div class="serverlog-panel">
                <div class="serverlog-panel__head">
                    <h4>Client versions</h4>
                </div>
                <div class="serverlog-retention">
                    <div v-for="row in clientTypeRows" :key="row.label" class="serverlog-retention__row">
                        <span class="serverlog-retention__label">{{ row.label }}</span>
                        <el-progress
                            class="serverlog-retention__bar"
                            :percentage="row.pct"
                            :stroke-width="14"
                            :show-text="false"
                            :color="progressColor"
                        />
                        <span class="serverlog-retention__value">{{ row.pct }}% ({{ row.sessions }} sessions)</span>
                    </div>
                </div>
                <p v-if="hasUnknownClientType" class="serverlog-panel__note">
                    "Unknown" is older sessions imported from server_messages.log, which doesn't record the build id.
                </p>
            </div>

            <div class="serverlog-summary">
                <h4>Bottom line</h4>
                <p>{{ bottomLine.intake }}</p>
                <p>{{ bottomLine.base }}</p>
                <p>{{ bottomLine.night }}</p>
            </div>
        </template>
    </admin-panel>
</template>

<script>
import AdminPanel from './AdminPanel.vue'
import BarChartComponent from './BarChartComponent.vue'
import LineChartComponent from './LineChartComponent.vue'
import { apiGet, formatDateTime, notice, reportError } from '../admin/adminUtils.js'
import { CHART_TEXT_COLOR, CHART_GRID_COLOR } from '../chartColors.js'
import { nightBandPlugin, weekMeansPlugin, valueLabelsPlugin } from '../admin/chartPlugins.js'

const BLUE = 'rgba(58, 135, 229, 1)'
const BLUE_FILL = 'rgba(58, 135, 229, 0.15)'
const ORANGE = 'rgba(235, 104, 52, 1)'
const MUTED = 'rgba(154, 152, 143, 1)'

export default {
    name: 'ServerLog',
    components: { AdminPanel, BarChartComponent, LineChartComponent },
    data() {
        return {
            loading: false,
            data: null,
            days: 30,
            dayOptions: [8, 14, 30, 60, 90],
            nightBandPlugin,
            weekMeansPlugin,
            valueLabelsPlugin,
        }
    },
    computed: {
        subtitle() {
            if (!this.data || !this.data.window) return ''
            const w = this.data.window
            const logins = this.data.total_logins ? `${this.data.total_logins.toLocaleString()} logins · ` : ''
            return `${w.from} – ${w.to} · ${logins}${w.days} days analysed`
        },
        earlyDataNote() {
            const d = this.data
            if (!d.logging_since) return ''
            const since = this.formatDateTime(d.logging_since)
            return `Logging active since ${since} · ${d.sessions_so_far} session(s) so far · `
                + 'not a full day yet, so the trend report below isn\'t available'
        },
        recentHourlyChartData() {
            const rows = this.data.recent_hourly
            return {
                labels: rows.map((r) => this.hourLabel(r.hour)),
                datasets: [{ label: 'Sessions', data: rows.map((r) => r.count), backgroundColor: 'rgba(58, 135, 229, 1)' }],
            }
        },
        tiles() {
            const d = this.data
            const back7 = d.retention[7].back
            const total7 = d.retention[7].total
            const retPct = total7 ? Math.round((100 * back7) / total7) : 0
            const baseDelta = d.established.after - d.established.before
            return [
                {
                    key: 'Active accounts / day',
                    value: d.active_mean,
                    sub: `mean · trend ${this.signed(d.trend.slope_per_week)}/week (r=${d.trend.r})`,
                    tone: 'flat',
                },
                {
                    key: 'New registrations',
                    value: d.new_total,
                    sub: `in ${d.window.days} days · ${d.weeks[0]?.mean ?? 0} → ${d.weeks[d.weeks.length - 1]?.mean ?? 0} per day`,
                    tone: d.new_ratio > 1.15 ? 'up' : d.new_ratio < 0.85 ? 'down' : 'flat',
                },
                {
                    key: 'Return within 7 days',
                    value: `${retPct}%`,
                    sub: `${back7} of ${total7} new accounts`,
                    tone: total7 && back7 / total7 >= 0.5 ? 'up' : 'down',
                },
                {
                    key: `Established base wk1 → wk${d.weeks.length}`,
                    value: this.signed(baseDelta),
                    sub: `${d.established.before} → ${d.established.after} · ${d.established.gone} gone, ${d.established.back} returned`,
                    tone: baseDelta > 0 ? 'up' : baseDelta < 0 ? 'down' : 'flat',
                },
            ]
        },
        activeChartData() {
            const d = this.data
            const values = d.active_per_day.map((p) => p.count)
            const n = values.length
            const mx = (n - 1) / 2
            const trendValues = values.map((_, i) => d.active_mean + d.trend.slope_per_day * (i - mx))
            return {
                labels: d.active_per_day.map((p) => this.shortDate(p.date)),
                datasets: [
                    {
                        label: 'Active accounts',
                        data: values,
                        borderColor: BLUE,
                        backgroundColor: BLUE_FILL,
                        pointRadius: 3,
                        tension: 0.25,
                        fill: false,
                    },
                    {
                        label: 'Trend',
                        data: trendValues,
                        borderColor: MUTED,
                        borderDash: [6, 4],
                        pointRadius: 0,
                        tension: 0,
                    },
                ],
            }
        },
        activeTrendNote() {
            const { r, slope_per_day: slope } = this.data.trend
            if (Math.abs(r) < 0.5) {
                return `flat – day-to-day noise (sd ${this.data.active_sd}) is larger than the trend`
            }
            const dir = slope > 0 ? 'rising' : 'falling'
            return `${dir} – r=${Math.abs(r)} against day-to-day noise (sd ${this.data.active_sd})`
        },
        newRegChartData() {
            const d = this.data
            return {
                labels: d.new_per_day.map((p) => this.shortDate(p.date)),
                datasets: [{ label: 'New accounts', data: d.new_per_day.map((p) => p.count), backgroundColor: ORANGE }],
            }
        },
        /** data.weeks trägt Datumsgrenzen; hier auf die Balken-Indizes des Charts gemappt. */
        weekMeanBlocks() {
            const dates = this.data.new_per_day.map((p) => p.date)
            return this.data.weeks.map((w) => ({
                from: dates.indexOf(w.from),
                to: dates.indexOf(w.to),
                mean: w.mean,
            }))
        },
        newRegNote() {
            const ratio = this.data.new_ratio
            if (ratio > 1.3) return `clearly rising – ${ratio}x the first week`
            if (ratio < 0.77) return `falling – ${ratio}x the first week`
            return 'roughly flat across the period'
        },
        retentionRows() {
            const r = this.data.retention
            return [1, 3, 7].map((w) => {
                const { back, total } = r[w]
                return {
                    label: w === 1 ? 'next day' : `within ${w} days`,
                    back,
                    total,
                    pct: total ? Math.round((100 * back) / total) : 0,
                }
            })
        },
        seenPct() {
            return this.data.new_total ? Math.round((100 * this.data.new_seen) / this.data.new_total) : 0
        },
        clientTypeRows() {
            const rows = this.data.client_types || []
            const totalSessions = rows.reduce((sum, r) => sum + r.sessions, 0)
            const labels = { 1: 'Qt Widget', 2: 'QML' }
            return rows.map((r) => ({
                label: r.type === null ? 'Unknown' : (labels[r.type] || `Type ${r.type}`),
                sessions: r.sessions,
                pct: totalSessions ? Math.round((100 * r.sessions) / totalSessions) : 0,
            }))
        },
        hasUnknownClientType() {
            return (this.data.client_types || []).some((r) => r.type === null)
        },
        oneDayPct() {
            return Math.round(100 * this.data.histogram.one_day_share)
        },
        histogramChartData() {
            const h = this.data.histogram
            return {
                labels: h.buckets.map((b) => b.label),
                datasets: [{ label: 'Newcomers', data: h.buckets.map((b) => b.count), backgroundColor: ORANGE }],
            }
        },
        nightWindow() {
            const [a, b] = this.data.hourly.night
            return `${String(a).padStart(2, '0')}:00–${String(b).padStart(2, '0')}:00`
        },
        nightSubNote() {
            return this.data.hourly.night_index >= 1.25
                ? 'The daily low in absolute terms – yet new accounts are clearly over-represented there'
                : 'The daily low in absolute terms – new accounts arrive there in proportion to traffic'
        },
        loginsHourChartData() {
            return this.hourChart(this.data.hourly.logins_per_hour, BLUE)
        },
        indexHourChartData() {
            const idx = this.data.hourly.new_index_per_hour
            const colors = idx.map((v) => (v >= 1 ? ORANGE : 'rgba(185, 183, 176, 0.6)'))
            return this.hourChartColored(idx, colors)
        },
        nightNote() {
            const h = this.data.hourly
            return `${this.nightWindow} holds just ${Math.round(100 * h.night_login_share)}% of all logins, but `
                + `${Math.round(100 * h.night_new_share)}% of all first logins by new accounts (index ${h.night_index}).`
        },
        gapNote() {
            const g = this.data.longest_gap
            if (!g) return ''
            const win = g.in_quiet_window ? ' – inside the quiet window' : ''
            return `Longest login-free stretch in the period: ${g.minutes} min, ${g.from} → ${g.to}${win}.`
        },
        bottomLine() {
            const d = this.data
            const back7 = d.retention[7].back
            const total7 = d.retention[7].total
            const neverPct = total7 ? Math.round(100 * (1 - back7 / total7)) : 0
            const first = d.weeks[0]?.mean ?? 0
            const last = d.weeks[d.weeks.length - 1]?.mean ?? 0
            const intake = d.new_ratio > 1.15
                ? `Intake is growing (${first} → ${last} new accounts/day), the active base is not: ${neverPct}% of the newcomers never come back.`
                : `Intake is steady at about ${last} new accounts/day and the active base is flat: ${neverPct}% of the newcomers never come back.`
            const base = `In the established base, ${d.established.back} returners replace the ${d.established.gone} who left. `
                + 'The lever is retention, not acquisition.'
            const night = d.hourly.night_index >= 1.25
                ? `Above all in the ${this.nightWindow} window, where proportionally ${d.hourly.night_index}x as many newcomers arrive as there is traffic – and nobody to play with.`
                : 'No single quiet window stands out as an acquisition/traffic mismatch right now.'
            return { intake, base, night }
        },
        lineOptions() {
            return {
                scales: { y: { ticks: { color: CHART_TEXT_COLOR }, grid: { color: CHART_GRID_COLOR } } },
                plugins: {
                    valueLabels: { show: true, mode: 'edges', datasetIndex: 0, color: CHART_TEXT_COLOR },
                },
            }
        },
        barOptions() {
            return {
                plugins: {
                    valueLabels: { show: true, mode: 'all', datasetIndex: 0, color: CHART_TEXT_COLOR },
                    weekMeans: { weeks: this.weekMeanBlocks, color: CHART_TEXT_COLOR },
                },
            }
        },
        barOptionsCompact() {
            return { plugins: { legend: { display: false } } }
        },
        histogramOptions() {
            return {
                plugins: {
                    legend: { display: false },
                    valueLabels: { show: true, mode: 'all', datasetIndex: 0, color: CHART_TEXT_COLOR },
                },
            }
        },
        loginsHourOptions() {
            const [from, to] = this.data.hourly.night
            return {
                plugins: {
                    legend: { display: false },
                    nightBand: { from, to, label: this.nightWindow },
                },
            }
        },
        indexHourOptions() {
            const [from, to] = this.data.hourly.night
            return {
                plugins: {
                    legend: { display: false },
                    nightBand: { from, to, label: this.nightWindow },
                },
            }
        },
        progressColor() {
            return BLUE
        },
    },
    mounted() {
        this.load()
    },
    methods: {
        formatDateTime,
        signed(v) {
            return v > 0 ? `+${v}` : `${v}`
        },
        shortDate(d) {
            const m = String(d).match(/^\d{4}-(\d{2})-(\d{2})$/)
            return m ? `${m[2]}.${m[1]}.` : d
        },
        hourLabel(bucket) {
            const m = String(bucket).match(/^(\d{4}-\d{2}-\d{2}) (\d{2}):00:00$/)
            return m ? `${this.shortDate(m[1])} ${m[2]}h` : bucket
        },
        hourChart(values, color) {
            return {
                labels: values.map((_, h) => String(h).padStart(2, '0')),
                datasets: [{ label: '', data: values, backgroundColor: color }],
            }
        },
        hourChartColored(values, colors) {
            return {
                labels: values.map((_, h) => String(h).padStart(2, '0')),
                datasets: [{ label: '', data: values, backgroundColor: colors }],
            }
        },
        async load() {
            this.loading = true
            try {
                const data = await apiGet('/serverlog', { days: this.days })
                if (!data.success) {
                    notice(data.msg || 'Could not load the server log.', 'warning')
                    this.data = null
                    return
                }
                this.data = data
            } catch (err) {
                reportError(err, 'Could not load the server log.')
                this.data = null
            } finally {
                this.loading = false
            }
        },
    },
}
</script>
