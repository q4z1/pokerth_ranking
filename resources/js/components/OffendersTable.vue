<template>
    <admin-panel
        title="Repeat offenders"
        :subtitle="subtitle"
        :count="filteredRows.length"
        :loading="loading"
        :total="filteredRows.length"
        :page="page"
        :page-size="pageSize"
        @refresh="load"
        @update:page="page = $event"
        @update:pageSize="onPageSizeChange"
    >
        <template #toolbar>
            <el-input
                v-model="search"
                class="admin-toolbar__search"
                placeholder="Search player"
                :prefix-icon="Search"
                clearable
            />
            <el-tooltip content="Minimum number of reports" placement="top">
                <el-input-number v-model="threshold" :min="1" :max="50" size="default" controls-position="right" />
            </el-tooltip>
            <el-checkbox v-model="openOnly" border>Open reports only</el-checkbox>
            <el-checkbox v-model="hideBanned" border>Hide banned</el-checkbox>
        </template>

        <el-table
            v-loading="loading"
            class="admin-table"
            :data="pagedRows"
            :row-class-name="rowClass"
            row-key="player_id"
            stripe
            :empty-text="emptyText"
            :default-sort="{ prop: 'total_reports', order: 'descending' }"
            @sort-change="onSortChange"
        >
            <el-table-column label="Player" min-width="200">
                <template #default="{ row }">
                    <player-ref :player="row" />
                </template>
            </el-table-column>
            <el-table-column prop="gamename_reports" label="Table names" width="130" align="right" sortable="custom" />
            <el-table-column prop="avatar_reports" label="Avatars" width="110" align="right" sortable="custom" />
            <el-table-column prop="total_reports" label="Total" width="100" align="right" sortable>
                <template #default="{ row }">
                    <strong :class="severityClass(row)">{{ row.total_reports }}</strong>
                </template>
            </el-table-column>
            <el-table-column prop="open_reports" label="Open" width="90" align="right" sortable="custom" />
            <el-table-column prop="distinct_reporters" label="Reporters" width="120" align="right" sortable>
                <template #default="{ row }">
                    <el-tooltip content="Number of different players who reported them" placement="top">
                        <span>{{ row.distinct_reporters }}</span>
                    </el-tooltip>
                </template>
            </el-table-column>
            <el-table-column prop="last_report" label="Last report" width="150" sortable>
                <template #default="{ row }">{{ formatDateTime(row.last_report) }}</template>
            </el-table-column>
            <el-table-column label="Action" width="230" align="right">
                <template #default="{ row }">
                    <el-button size="small" text :icon="View" @click="$emit('show-reports', row)">Reports</el-button>
                    <el-button
                        v-if="row.exists && !row.banned"
                        size="small"
                        type="danger"
                        @click="ban(row)"
                    >
                        Ban
                    </el-button>
                    <el-button v-else-if="row.banned" size="small" type="success" plain @click="unban(row)">
                        Unban
                    </el-button>
                </template>
            </el-table-column>
        </el-table>
    </admin-panel>
</template>

<script>
import { Search, View } from '@element-plus/icons-vue'
import AdminPanel from './AdminPanel.vue'
import PlayerRef from './PlayerRef.vue'
import {
    REPEAT_THRESHOLD,
    apiGet,
    apiPost,
    confirmAction,
    formatDateTime,
    listMixin,
    notice,
    reportError,
} from '../admin/adminUtils.js'

export default {
    name: 'OffendersTable',
    components: { AdminPanel, PlayerRef },
    mixins: [listMixin],
    emits: ['show-reports', 'changed'],
    data() {
        return {
            threshold: REPEAT_THRESHOLD,
            hideBanned: true,
            openOnly: true,
            sortProp: 'total_reports',
            sortOrder: 'descending',
            Search,
            View,
        }
    },
    computed: {
        subtitle() {
            const open = this.filteredRows.filter((r) => !r.banned && r.exists).length
            return `${open} not banned yet`
        },
        emptyText() {
            return this.loading ? 'Loading…' : 'No player reached the selected number of reports.'
        },
        baseRows() {
            return this.rows.filter((row) => {
                if (row.total_reports < this.threshold) return false
                if (this.openOnly && row.open_reports < 1) return false
                if (this.hideBanned && row.banned) return false
                return true
            })
        },
    },
    watch: {
        threshold() {
            this.page = 1
        },
    },
    mounted() {
        this.load()
    },
    methods: {
        formatDateTime,
        matches(row, term) {
            return (row.username || '').toLowerCase().includes(term) || String(row.player_id).includes(term)
        },
        severityClass(row) {
            if (row.total_reports >= REPEAT_THRESHOLD * 3) return 'severity--high'
            if (row.total_reports >= REPEAT_THRESHOLD) return 'severity--mid'
            return ''
        },
        rowClass({ row }) {
            if (row.banned) return 'is-banned'
            if (row.total_reports >= REPEAT_THRESHOLD * 3) return 'is-repeat'
            return ''
        },
        async load() {
            this.loading = true
            try {
                const data = await apiGet('/reports/offenders')
                this.rows = data.success ? data.list : []
                if (!data.success) notice(data.msg || 'Could not load offenders.', 'warning')
            } catch (err) {
                reportError(err, 'Could not load offenders.')
            } finally {
                this.loading = false
            }
        },
        async ban(row) {
            try {
                await confirmAction(
                    `Ban "${row.username}"? ` +
                        `${row.total_reports} report(s) from ${row.distinct_reporters} different player(s) — ` +
                        `${row.gamename_reports} table name, ${row.avatar_reports} avatar. ` +
                        'All open reports against this player will be closed as "creator banned".',
                    'Ban player',
                    { confirmButtonText: 'Ban player', confirmButtonClass: 'el-button--danger' }
                )
            } catch {
                return
            }
            try {
                const data = await apiPost(`/banlist/${row.player_id}`, { action: 'ban', resolve_reports: 1 })
                if (!data.success) return notice(data.msg || 'Ban failed.', 'error')
                notice(`${row.username} banned — ${data.resolved_reports ?? 0} report(s) closed.`)
                this.$emit('changed')
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
                this.$emit('changed')
                this.load()
            } catch (err) {
                reportError(err, 'Unban failed.')
            }
        },
    },
}
</script>
