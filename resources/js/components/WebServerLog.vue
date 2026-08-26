<template>
    <admin-panel
        title="Webserver Log"
        :subtitle="subtitle"
        :loading="loading"
        @refresh="load"
    >
        <template #toolbar>
            <el-select v-model="hours" class="admin-toolbar__select" @change="load">
                <el-option v-for="opt in hourOptions" :key="opt" :label="hourLabel(opt)" :value="opt" />
            </el-select>
        </template>

        <div v-if="!loading && data && !data.series.length" class="serverlog-empty">
            No webserver traffic has been logged yet.
        </div>

        <template v-else-if="data">
            <div class="serverlog-tiles">
                <div v-for="tile in tiles" :key="tile.key" class="serverlog-tile">
                    <div class="serverlog-tile__key">{{ tile.key }}</div>
                    <div class="serverlog-tile__value" :class="`tone-${tile.tone}`">{{ tile.value }}</div>
                    <div class="serverlog-tile__sub">{{ tile.sub }}</div>
                </div>
            </div>

            <div class="serverlog-grid-2">
                <div class="serverlog-panel">
                    <div class="serverlog-panel__head">
                        <h4>Hits per 5 min</h4>
                    </div>
                    <div class="serverlog-chart">
                        <line-chart-component :chart-data="hitsChartData" :options="hitsOptions" :plugins="[attackBandsPlugin]" />
                    </div>
                </div>
                <div class="serverlog-panel">
                    <div class="serverlog-panel__head">
                        <h4>Distinct source IPs per 5 min</h4>
                    </div>
                    <div class="serverlog-chart">
                        <line-chart-component :chart-data="uniqueChartData" :options="uniqueOptions" :plugins="[attackBandsPlugin]" />
                    </div>
                </div>
            </div>
            <p v-if="!hasFullUniqueData" class="serverlog-panel__note" style="padding: 0 1rem;">
                Distinct-IP and per-vhost tracking were added recently - older points in this window show a gap.
            </p>
            <p class="serverlog-panel__note" style="padding: 0 1rem;">
                Darker shading marks a confirmed filter-on period. Lighter shading marks only the measured span
                where traffic sat over the threshold, with no confirmation of how long the filter itself stayed on.
            </p>

            <div class="serverlog-panel">
                <div class="serverlog-panel__head">
                    <h4>Busiest vhosts in this window</h4>
                    <span class="serverlog-panel__note">{{ hasVhostNote }}</span>
                </div>
                <div v-if="data.vhosts.length" class="serverlog-chart">
                    <bar-chart-component :chart-data="vhostsChartData" :options="barOptionsCompact" />
                </div>
                <p v-else class="serverlog-panel__note">No per-vhost breakdown in this window yet.</p>
            </div>

            <div class="serverlog-panel">
                <div class="serverlog-panel__head">
                    <h4>Filter events</h4>
                    <span class="serverlog-panel__note">{{ data.events.length }} in this window</span>
                </div>
                <p class="serverlog-panel__note">{{ reconstructionNote }}</p>
                <el-table
                    v-if="data.events.length"
                    class="admin-table"
                    :data="data.events"
                    max-height="420"
                    :empty-text="'No filter switches in this window.'"
                >
                    <el-table-column prop="ts" label="Time" width="160">
                        <template #default="{ row }">{{ formatDateTime(row.ts) }}</template>
                    </el-table-column>
                    <el-table-column label="Action" width="150">
                        <template #default="{ row }">
                            <el-tag :type="actionTagType(row)" size="small" disable-transitions>
                                {{ actionLabel(row) }}
                            </el-tag>
                        </template>
                    </el-table-column>
                    <el-table-column label="Type" width="150">
                        <template #default="{ row }">
                            <el-tag v-if="row.classification" :type="classificationTagType(row.classification.key)" size="small" plain disable-transitions>
                                {{ classificationShortLabel(row.classification.key) }}
                            </el-tag>
                            <span v-else class="admin-muted">—</span>
                        </template>
                    </el-table-column>
                    <el-table-column label="Trigger" width="90">
                        <template #default="{ row }">
                            <el-tag :type="row.trigger === 'manual' ? 'info' : 'warning'" size="small" plain disable-transitions>
                                {{ row.trigger === 'manual' ? 'Manual' : 'Auto' }}
                            </el-tag>
                        </template>
                    </el-table-column>
                    <el-table-column label="Reason" min-width="260">
                        <template #default="{ row }">{{ reasonText(row) }}</template>
                    </el-table-column>
                    <el-table-column label="Duration" width="130">
                        <template #default="{ row }">{{ duration(row) }}</template>
                    </el-table-column>
                </el-table>
                <p v-else class="serverlog-panel__note">No filter switches in this window.</p>
            </div>

            <div class="serverlog-summary">
                <h4>Bottom line</h4>
                <p>{{ bottomLine }}</p>
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
import { attackBandsPlugin } from '../admin/chartPlugins.js'

const BLUE = 'rgba(58, 135, 229, 1)'
const BLUE_FILL = 'rgba(58, 135, 229, 0.15)'
const ORANGE = 'rgba(235, 104, 52, 1)'
const CONFIRMED_BAND_COLOR = 'rgba(226, 84, 104, 0.18)'
const UNCONFIRMED_BAND_COLOR = 'rgba(226, 84, 104, 0.07)'

const REASON_TYPE_LABELS = {
    hit_spike: 'sudden spike',
    sustained_rate: 'sustained high rate',
    unique_ip_burst: 'unique-IP burst',
}

const CLASSIFICATION_LABELS = {
    botnet: 'Botnet',
    scanner: 'Scanner/crawler',
    mixed: 'Mixed',
    unknown: 'Unclassified',
}

export default {
    name: 'WebServerLog',
    components: { AdminPanel, BarChartComponent, LineChartComponent },
    data() {
        return {
            loading: false,
            data: null,
            hours: 24,
            hourOptions: [3, 6, 12, 24, 48, 96, 168],
            attackBandsPlugin,
        }
    },
    computed: {
        subtitle() {
            if (!this.data || !this.data.window) return ''
            const w = this.data.window
            return `${w.from} – ${w.to} UTC · ${this.data.series.length} samples`
        },
        hasFullUniqueData() {
            return !!this.data && this.data.series.length > 0 && this.data.series.every((p) => p.unique_ips !== null)
        },
        hasVhostNote() {
            return this.data && this.data.has_vhost_data ? '' : 'only points with a per-vhost breakdown are counted'
        },
        tiles() {
            const d = this.data
            const c = d.current
            const s = d.stats
            return [
                {
                    key: 'Cloudflare filter',
                    value: c.filter_enabled ? 'Active' : 'Clear',
                    sub: c.filter_enabled
                        ? `since ${this.formatDateTime(c.enabled_since)} (${c.enabled_minutes} min)`
                        : c.grace_until
                            ? `observation window open until ${this.formatDateTime(c.grace_until)}`
                            : 'no attack detected right now',
                    tone: c.filter_enabled ? 'down' : 'up',
                },
                {
                    key: 'Triggers',
                    value: s.triggers,
                    sub: s.triggers > 0
                        ? `${s.botnet_triggers} botnet · ${s.scanner_triggers} scanner · ${s.minutes_filtered} min filtered`
                        : 'no attack detected in this window',
                    tone: s.triggers > 0 ? 'down' : 'flat',
                },
                {
                    key: 'Peak hits / 5 min',
                    value: s.peak_hits ?? '—',
                    sub: s.peak_unique_ips !== null ? `peak ${s.peak_unique_ips} distinct IPs / 5 min` : 'no distinct-IP data yet',
                    tone: 'flat',
                },
                {
                    key: 'Busiest vhost',
                    value: s.busiest_vhost ?? '—',
                    sub: d.vhosts.length ? `${Math.round(100 * d.vhosts[0].share)}% of tracked hits` : '',
                    tone: 'flat',
                },
            ]
        },
        hitsChartData() {
            const s = this.data.series
            return {
                labels: s.map((p) => this.tsLabel(p.ts)),
                datasets: [{
                    label: 'Hits / 5min',
                    data: s.map((p) => p.total),
                    borderColor: BLUE,
                    backgroundColor: BLUE_FILL,
                    pointRadius: 0,
                    borderWidth: 1.5,
                    tension: 0.15,
                    fill: true,
                }],
            }
        },
        uniqueChartData() {
            const s = this.data.series
            return {
                labels: s.map((p) => this.tsLabel(p.ts)),
                datasets: [{
                    label: 'Distinct IPs / 5min',
                    data: s.map((p) => p.unique_ips),
                    borderColor: ORANGE,
                    backgroundColor: 'rgba(235, 104, 52, 0.15)',
                    pointRadius: 0,
                    borderWidth: 1.5,
                    tension: 0.15,
                    fill: true,
                }],
            }
        },
        vhostsChartData() {
            const v = this.data.vhosts.slice(0, 10)
            return {
                labels: v.map((r) => r.name),
                datasets: [{ label: 'Hits', data: v.map((r) => r.hits), backgroundColor: ORANGE }],
            }
        },
        attackBands() {
            const series = this.data.series
            if (!series.length) return []
            const times = series.map((p) => this.tsMs(p.ts))
            const idxFor = (ms) => {
                const idx = times.findIndex((t) => t >= ms)
                return idx === -1 ? times.length - 1 : idx
            }
            const bands = []
            this.data.events.forEach((e) => {
                if (e.action === 'disable' && e.duration_minutes != null) {
                    // Confirmed filter-on period, from a real enable/disable pair.
                    // ts is when it was cleared (end of episode); duration looks back to the enable.
                    const endMs = this.tsMs(e.ts)
                    const startMs = endMs - e.duration_minutes * 60000
                    bands.push({ from: idxFor(startMs), to: idxFor(endMs), color: CONFIRMED_BAND_COLOR })
                } else if (e.action === 'detected' && e.trigger_span_minutes != null) {
                    // Not confirmed: only the measured span where traffic itself sat
                    // over the threshold, not how long the filter was actually on
                    // (which AttackCheck holds for a fixed period once triggered,
                    // regardless of how fast traffic drops back down) - shown lighter
                    // and separately so it isn't mistaken for a confirmed duration.
                    const startMs = this.tsMs(e.ts)
                    const endMs = startMs + e.trigger_span_minutes * 60000
                    bands.push({ from: idxFor(startMs), to: idxFor(endMs), color: UNCONFIRMED_BAND_COLOR })
                }
            })
            if (this.data.current.filter_enabled && this.data.current.enabled_since) {
                bands.push({
                    from: idxFor(this.tsMs(this.data.current.enabled_since)),
                    to: times.length - 1,
                    color: CONFIRMED_BAND_COLOR,
                })
            }
            return bands
        },
        hitsOptions() {
            return { plugins: { legend: { display: false }, attackBands: { bands: this.attackBands } } }
        },
        uniqueOptions() {
            return { plugins: { legend: { display: false }, attackBands: { bands: this.attackBands } } }
        },
        barOptionsCompact() {
            return { plugins: { legend: { display: false } } }
        },
        bottomLine() {
            const d = this.data
            const s = d.stats
            const w = d.window
            if (s.triggers === 0) {
                return `No attack was detected in the last ${w.hours}h - the filter stayed off throughout.`
            }
            const confirmed = d.events.filter((e) => e.action === 'enable' && e.trigger === 'auto').length
            const unconfirmed = s.triggers - confirmed
            const vhost = s.busiest_vhost ? ` Most of the traffic landed on ${s.busiest_vhost}.` : ''
            const kinds = []
            if (s.botnet_triggers) kinds.push(`${s.botnet_triggers} botnet-like`)
            if (s.scanner_triggers) kinds.push(`${s.scanner_triggers} single scanner/crawler`)
            const kindsNote = kinds.length ? ` Of those, ${kinds.join(' and ')}.` : ''
            let filteredNote
            if (s.minutes_filtered > 0) {
                filteredNote = ` The filter was confirmed on for ${s.minutes_filtered} min in total`
                    + (unconfirmed ? `, plus ${unconfirmed} detection(s) with no confirmed on-time.` : '.')
            } else if (unconfirmed) {
                filteredNote = ' None of these have a confirmed on-time - traffic-only detections, from before '
                    + 'filter-switch logging started.'
            } else {
                filteredNote = ''
            }
            return `${s.triggers} attack(s) were detected in the last ${w.hours}h.${filteredNote}${kindsNote}${vhost}`
        },
        reconstructionNote() {
            const d = this.data
            const hasDerived = d.events.some((e) => e.source === 'derived')
            if (!d.events_log_started) {
                return hasDerived
                    ? 'Confirmed filter-switch logging has not recorded a real switch yet - rows marked "Detected (unconfirmed)" below are '
                        + 'reconstructed from raw hit/rate traffic. Botnet-style attacks recognised only by a burst of distinct source IPs '
                        + "can't be reconstructed this way, since that history wasn't kept before logging started."
                    : 'Confirmed filter-switch logging has not recorded a switch in this window yet.'
            }
            if (!hasDerived) {
                return `Confirmed filter-switch logging since ${this.formatDateTime(d.events_log_started)}.`
            }
            return `Confirmed filter-switch logging started ${this.formatDateTime(d.events_log_started)}. Rows before that marked `
                + '"Detected (unconfirmed)" are reconstructed from raw traffic where possible; botnet-style attacks recognised only by a '
                + "burst of distinct source IPs can't be reconstructed for that earlier period."
        },
    },
    mounted() {
        this.load()
    },
    methods: {
        formatDateTime,
        hourLabel(h) {
            return h < 24 ? `Last ${h}h` : `Last ${h / 24} day${h === 24 ? '' : 's'}`
        },
        tsLabel(ts) {
            const m = String(ts).match(/^\d{4}-(\d{2})-(\d{2}) (\d{2}):(\d{2})/)
            if (!m) return ts
            const [, mo, d, h, mi] = m
            return this.hours <= 24 ? `${h}:${mi}` : `${d}.${mo} ${h}:${mi}`
        },
        tsMs(ts) {
            return Date.parse(String(ts).replace(' ', 'T') + 'Z')
        },
        actionTagType(row) {
            if (row.action === 'enable') return 'danger'
            if (row.action === 'disable') return 'success'
            return 'warning' // 'detected' - reconstructed, not a confirmed filter switch
        },
        actionLabel(row) {
            if (row.action === 'enable') return 'Activated'
            if (row.action === 'disable') return 'Cleared'
            return 'Detected (unconfirmed)'
        },
        classificationTagType(key) {
            if (key === 'botnet') return 'danger'
            if (key === 'scanner') return 'warning'
            if (key === 'mixed') return 'info'
            return 'info'
        },
        classificationShortLabel(key) {
            return CLASSIFICATION_LABELS[key] || 'Unclassified'
        },
        reasonText(row) {
            if (row.reason) {
                const typeLabel = REASON_TYPE_LABELS[row.reason_type]
                return typeLabel ? `${typeLabel} - ${row.reason}` : row.reason
            }
            return row.trigger === 'manual' ? 'Switched by hand' : '—'
        },
        duration(row) {
            if (row.action === 'disable' && row.duration_minutes != null) {
                return `${row.duration_minutes} min (confirmed)`
            }
            if (row.action === 'detected' && row.trigger_span_minutes != null) {
                return `≥${row.trigger_span_minutes} min over threshold (filter duration not confirmed)`
            }
            if (row.action === 'enable' && this.data.current.filter_enabled && row.ts === this.data.current.enabled_since) {
                return `ongoing (${this.data.current.enabled_minutes} min)`
            }
            return '—'
        },
        async load() {
            this.loading = true
            try {
                const data = await apiGet('/webserverlog', { hours: this.hours })
                if (!data.success) {
                    notice(data.msg || 'Could not load the webserver log.', 'warning')
                    this.data = null
                    return
                }
                this.data = data
            } catch (err) {
                reportError(err, 'Could not load the webserver log.')
                this.data = null
            } finally {
                this.loading = false
            }
        },
    },
}
</script>
