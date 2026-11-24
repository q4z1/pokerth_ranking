<template>
    <admin-panel
        title="Collusion / multi-seat"
        :subtitle="subtitle"
        :count="rings.length"
        :loading="loading"
        @refresh="load"
    >
        <template #toolbar>
            <el-tooltip content="Time window of the shared tables" placement="top">
                <el-select v-model="since" class="collusion-filter" @change="load">
                    <el-option label="All time" value="all" />
                    <el-option label="Last day" value="day" />
                    <el-option label="Last week" value="week" />
                    <el-option label="Last month" value="month" />
                    <el-option label="Last year" value="year" />
                </el-select>
            </el-tooltip>
            <el-tooltip content="Minimum confidence" placement="top">
                <el-select v-model="confidence" class="collusion-filter" @change="load">
                    <el-option label="Any confidence" value="" />
                    <el-option label="Medium and up" value="medium" />
                    <el-option label="High only" value="high" />
                </el-select>
            </el-tooltip>
            <el-tooltip content="Minimum shared ranked tables per pair" placement="top">
                <el-input-number v-model="minTables" :min="2" :max="50" size="default" controls-position="right" @change="load" />
            </el-tooltip>
            <el-tooltip content="Maximum number of rings" placement="top">
                <el-input-number v-model="limit" :min="5" :max="500" :step="5" size="default" controls-position="right" @change="load" />
            </el-tooltip>
        </template>

        <div v-loading="loading" class="collusion-list">
            <p v-if="!loading && !rings.length" class="admin-muted collusion-empty">
                No suspicious rings for these filters.
            </p>

            <p v-else-if="rings.length < total" class="admin-muted collusion-note">
                Showing top {{ rings.length }} of {{ total }} rings — raise the limit for more.
            </p>

            <el-card v-for="(ring, i) in rings" :key="i" shadow="never" class="collusion-ring">
                <template #header>
                    <div class="collusion-ring__head">
                        <span class="collusion-ring__idx">#{{ i + 1 }}</span>
                        <el-tag v-if="ring.top_rank" type="warning" effect="dark" round disable-transitions>
                            best rank #{{ ring.top_rank }}
                        </el-tag>
                        <el-tag :type="confidenceType(ring.confidence)" disable-transitions>
                            {{ ring.confidence }}
                        </el-tag>
                        <span class="collusion-ring__meta">
                            {{ ring.size }} accounts · {{ ring.shared_tables }} shared ranked tables
                        </span>
                        <span class="collusion-ring__signals">{{ signals(ring) }}</span>
                        <span class="collusion-ring__seen">last together: {{ formatDateTime(ring.last_seen) }}</span>
                    </div>
                </template>

                <el-table class="admin-table" :data="ring.members" row-key="player_id" stripe :row-class-name="rowClass">
                    <el-table-column label="Player" min-width="180">
                        <template #default="{ row }">
                            <player-ref :player="row" />
                            <el-tooltip
                                v-if="row.pw_group"
                                :content="'Shares password group ' + row.pw_group + ' with other members'"
                                placement="top"
                            >
                                <el-tag size="small" type="danger" effect="plain" class="collusion-pw" disable-transitions>
                                    pw {{ row.pw_group }}
                                </el-tag>
                            </el-tooltip>
                        </template>
                    </el-table-column>
                    <el-table-column label="Rank" width="90" align="right">
                        <template #default="{ row }">
                            <strong v-if="row.rank">#{{ row.rank }}</strong>
                            <span v-else>—</span>
                        </template>
                    </el-table-column>
                    <el-table-column label="Score" width="100" align="right">
                        <template #default="{ row }">{{ row.score ?? '—' }}</template>
                    </el-table-column>
                    <el-table-column label="Games" width="90" align="right">
                        <template #default="{ row }">{{ row.games ?? '—' }}</template>
                    </el-table-column>
                    <el-table-column label="Last login" width="130">
                        <template #default="{ row }">{{ row.last_login ? row.last_login.slice(0, 10) : 'never' }}</template>
                    </el-table-column>
                    <el-table-column label="IPs" width="70" align="right" prop="ips" />
                    <el-table-column label="Action" width="130" align="right">
                        <template #default="{ row }">
                            <el-button v-if="!row.banned" size="small" type="danger" @click="ban(row)">Ban</el-button>
                            <el-button v-else size="small" type="success" plain @click="unban(row)">Unban</el-button>
                        </template>
                    </el-table-column>
                </el-table>

                <p v-if="ring.shared_ips && ring.shared_ips.length" class="collusion-ring__ips">
                    shared IPs: {{ ring.shared_ips.join(', ') }}
                </p>
            </el-card>
        </div>
    </admin-panel>
</template>

<script>
import AdminPanel from './AdminPanel.vue'
import PlayerRef from './PlayerRef.vue'
import { apiGet, apiPost, confirmAction, formatDateTime, notice, reportError } from '../admin/adminUtils.js'

const SINCE_DAYS = { day: 1, week: 7, month: 30, year: 365 }

export default {
    name: 'CollusionTable',
    components: { AdminPanel, PlayerRef },
    data() {
        return {
            rings: [],
            total: 0,
            loading: false,
            since: 'all',
            confidence: '',
            minTables: 2,
            limit: 25,
        }
    },
    computed: {
        subtitle() {
            const banned = this.rings.reduce(
                (n, r) => n + r.members.filter((m) => !m.banned).length,
                0
            )
            return this.rings.length ? `${banned} member(s) not banned yet` : ''
        },
    },
    mounted() {
        this.load()
    },
    methods: {
        formatDateTime,
        confidenceType(c) {
            return { high: 'danger', medium: 'warning', review: 'info' }[c] || 'info'
        },
        signals(ring) {
            const s = []
            if (ring.pw_match) s.push('same password')
            if (ring.name_similar) s.push('similar nicks')
            if (ring.min_ip_accounts != null) s.push(`private IP (min ${ring.min_ip_accounts}/IP)`)
            return s.join(', ')
        },
        rowClass({ row }) {
            return row.banned ? 'is-banned' : ''
        },
        async load() {
            this.loading = true
            try {
                const params = {
                    min_tables: this.minTables,
                    limit: this.limit,
                }
                if (this.since !== 'all') params.days = SINCE_DAYS[this.since]
                if (this.confidence) params.confidence = this.confidence
                const data = await apiGet('/reports/collusion', params)
                this.rings = data.success ? data.rings : []
                this.total = data.success ? data.total : 0
                if (!data.success) notice(data.msg || 'Could not load collusion rings.', 'warning')
            } catch (err) {
                reportError(err, 'Could not load collusion rings.')
            } finally {
                this.loading = false
            }
        },
        async ban(row) {
            try {
                await confirmAction(
                    `Ban "${row.username}" (rank ${row.rank ? '#' + row.rank : 'n/a'})? ` +
                        'This account shares a ranked table and an IP/password link with the ring.',
                    'Ban player',
                    { confirmButtonText: 'Ban player', confirmButtonClass: 'el-button--danger' }
                )
            } catch {
                return
            }
            try {
                const data = await apiPost(`/banlist/${row.player_id}`, { action: 'ban', resolve_reports: 1 })
                if (!data.success) return notice(data.msg || 'Ban failed.', 'error')
                notice(`${row.username} banned.`)
                this.load()
            } catch (err) {
                reportError(err, 'Ban failed.')
            }
        },
        async unban(row) {
            try {
                const data = await apiPost(`/banlist/${row.player_id}`, { action: 'unban' })
                if (!data.success) return notice(data.msg || 'Unban failed.', 'error')
                notice(`${row.username} unbanned.`)
                this.load()
            } catch (err) {
                reportError(err, 'Unban failed.')
            }
        },
    },
}
</script>

<style scoped>
.collusion-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.collusion-ring__head {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px 12px;
}
.collusion-ring__idx {
    font-weight: 700;
}
.collusion-ring__meta {
    font-weight: 600;
}
.collusion-ring__signals,
.collusion-ring__seen {
    color: var(--el-text-color-secondary);
    font-size: 0.85em;
}
.collusion-ring__ips {
    margin: 10px 0 0;
    color: var(--el-text-color-secondary);
    font-size: 0.8em;
    word-break: break-all;
}
.collusion-filter {
    width: 150px;
}
.collusion-pw {
    margin-left: 6px;
}
.collusion-note,
.collusion-empty {
    margin: 0 0 4px;
}
</style>
