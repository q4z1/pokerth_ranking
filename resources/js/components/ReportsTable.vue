<template>
    <admin-panel
        :title="title"
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
                placeholder="Search player or content"
                :prefix-icon="Search"
                clearable
            />
            <el-select v-model="stateFilter" class="admin-toolbar__select" placeholder="Status">
                <el-option label="Open only" value="open" />
                <el-option label="All" value="all" />
                <el-option v-for="(meta, key) in states" :key="key" :label="meta.label" :value="Number(key)" />
            </el-select>
            <el-tooltip content="Only players with repeated reports" placement="top">
                <el-checkbox v-model="repeatOnly" border>Repeat offenders</el-checkbox>
            </el-tooltip>
        </template>

        <template v-if="selection.length" #bulk>
            <span class="admin-bulk__label">{{ selection.length }} selected</span>
            <el-button size="small" :icon="Close" @click="setState(selection, STATE_IGNORED)">Ignore</el-button>
            <el-button size="small" :icon="Warning" @click="setState(selection, STATE_WARNED)">Mark warned</el-button>
            <el-button size="small" :icon="Delete" type="danger" plain @click="removeReports(selection)">Delete</el-button>
            <el-button size="small" text @click="clearSelection">Clear</el-button>
        </template>

        <el-table
            ref="table"
            v-loading="loading"
            class="admin-table"
            :data="pagedRows"
            :row-class-name="rowClass"
            row-key="id"
            stripe
            :empty-text="emptyText"
            :default-sort="{ prop: 'timestamp', order: 'descending' }"
            @selection-change="selection = $event"
            @sort-change="onSortChange"
        >
            <el-table-column type="selection" width="42" :reserve-selection="false" />
            <el-table-column prop="id" label="ID" width="80" sortable="custom" />
            <el-table-column prop="timestamp" label="Reported" width="150" sortable="custom">
                <template #default="{ row }">{{ formatDateTime(row.timestamp) }}</template>
            </el-table-column>

            <el-table-column label="Player" min-width="180">
                <template #default="{ row }">
                    <player-ref :player="row.creator" :offences="row.offences" />
                </template>
            </el-table-column>

            <el-table-column :label="contentLabel" min-width="200">
                <template #default="{ row }">
                    <el-image
                        v-if="type === 'avatar'"
                        class="admin-avatar"
                        :src="avatarUrl(row)"
                        :preview-src-list="[avatarUrl(row)]"
                        preview-teleported
                        fit="contain"
                    >
                        <template #error><span class="admin-avatar__missing">missing</span></template>
                    </el-image>
                    <code v-else class="admin-code">{{ row.game_name }}</code>
                </template>
            </el-table-column>

            <el-table-column label="Reported by" min-width="150">
                <template #default="{ row }"><player-ref :player="row.reporter" /></template>
            </el-table-column>

            <el-table-column label="Status" width="120">
                <template #default="{ row }">
                    <el-tag :type="stateMeta(row.state).type" size="small" disable-transitions>
                        {{ stateMeta(row.state).label }}
                    </el-tag>
                </template>
            </el-table-column>

            <el-table-column label="Action" width="200" align="right">
                <template #default="{ row }">
                    <el-button
                        v-if="row.creator && row.creator.exists && !row.creator.banned"
                        size="small"
                        type="danger"
                        @click="banCreator(row)"
                    >
                        Ban
                    </el-button>
                    <el-button
                        v-else-if="row.creator && row.creator.banned"
                        size="small"
                        type="success"
                        plain
                        @click="unbanCreator(row)"
                    >
                        Unban
                    </el-button>
                    <el-dropdown trigger="click" @command="(cmd) => rowCommand(cmd, row)">
                        <el-button size="small" :icon="MoreFilled" />
                        <template #dropdown>
                            <el-dropdown-menu>
                                <el-dropdown-item command="ignore" :disabled="row.state === STATE_IGNORED">
                                    Ignore report
                                </el-dropdown-item>
                                <el-dropdown-item command="warn" :disabled="row.state === STATE_WARNED">
                                    Mark player warned
                                </el-dropdown-item>
                                <el-dropdown-item command="spam" :disabled="row.state === STATE_REPORTER_SPAM">
                                    Flag as report spam
                                </el-dropdown-item>
                                <el-dropdown-item command="reopen" :disabled="row.state === STATE_NEW" divided>
                                    Reopen
                                </el-dropdown-item>
                                <el-dropdown-item command="delete">Delete report</el-dropdown-item>
                            </el-dropdown-menu>
                        </template>
                    </el-dropdown>
                </template>
            </el-table-column>
        </el-table>
    </admin-panel>
</template>

<script>
import { Close, Delete, MoreFilled, Search, Warning } from '@element-plus/icons-vue'
import AdminPanel from './AdminPanel.vue'
import PlayerRef from './PlayerRef.vue'
import {
    REPEAT_THRESHOLD,
    REPORT_STATES,
    STATE_IGNORED,
    STATE_NEW,
    STATE_REPORTER_SPAM,
    STATE_WARNED,
    apiGet,
    apiPost,
    confirmAction,
    formatDateTime,
    listMixin,
    notice,
    reportError,
    stateMeta,
} from '../admin/adminUtils.js'

export default {
    name: 'ReportsTable',
    components: { AdminPanel, PlayerRef },
    mixins: [listMixin],
    props: {
        /** 'gamename' oder 'avatar' */
        type: { type: String, required: true },
        /** Vorbelegte Suche, z.B. beim Sprung aus der Offender-Liste. */
        preset: { type: String, default: '' },
    },
    emits: ['changed'],
    data() {
        return {
            stateFilter: 'open',
            repeatOnly: false,
            selection: [],
            sortProp: 'timestamp',
            sortOrder: 'descending',
            states: REPORT_STATES,
            Close,
            Delete,
            MoreFilled,
            Search,
            Warning,
            STATE_NEW,
            STATE_IGNORED,
            STATE_WARNED,
            STATE_REPORTER_SPAM,
        }
    },
    computed: {
        title() {
            return this.type === 'avatar' ? 'Avatar reports' : 'Table name reports'
        },
        subtitle() {
            const open = this.rows.filter((r) => r.state === STATE_NEW).length
            return `${open} open of ${this.rows.length}`
        },
        contentLabel() {
            return this.type === 'avatar' ? 'Avatar' : 'Table name'
        },
        emptyText() {
            return this.loading ? 'Loading…' : 'No reports match the current filter.'
        },
        baseRows() {
            return this.rows.filter((row) => {
                if (this.stateFilter === 'open' && row.state !== STATE_NEW) return false
                if (typeof this.stateFilter === 'number' && row.state !== this.stateFilter) return false
                if (this.repeatOnly && (row.offences?.total ?? 0) < REPEAT_THRESHOLD) return false
                return true
            })
        },
    },
    watch: {
        type: {
            handler() {
                this.selection = []
                this.load()
            },
        },
        preset: {
            immediate: true,
            handler(value) {
                if (value) {
                    this.search = value
                    this.stateFilter = 'all'
                }
            },
        },
    },
    mounted() {
        this.load()
    },
    methods: {
        formatDateTime,
        stateMeta,
        avatarUrl(row) {
            return `/images/avatars/game/${row.avatar_hash}.${row.avatar_type}`
        },
        rowClass({ row }) {
            if (row.creator?.banned) return 'is-banned'
            if ((row.offences?.total ?? 0) >= REPEAT_THRESHOLD) return 'is-repeat'
            return ''
        },
        matches(row, term) {
            return (
                String(row.id).includes(term) ||
                (row.game_name || '').toLowerCase().includes(term) ||
                (row.creator?.username || '').toLowerCase().includes(term) ||
                (row.reporter?.username || '').toLowerCase().includes(term)
            )
        },
        clearSelection() {
            this.$refs.table?.clearSelection()
            this.selection = []
        },
        async load() {
            this.loading = true
            try {
                const data = await apiGet(`/reports/${this.type}`)
                if (data.success) {
                    this.rows = data.list
                } else {
                    this.rows = []
                    notice(data.msg || 'Could not load reports.', 'warning')
                }
            } catch (err) {
                reportError(err, 'Could not load reports.')
            } finally {
                this.loading = false
                this.clearSelection()
            }
        },
        rowCommand(command, row) {
            const map = {
                ignore: STATE_IGNORED,
                warn: STATE_WARNED,
                spam: STATE_REPORTER_SPAM,
                reopen: STATE_NEW,
            }
            if (command === 'delete') return this.removeReports([row])
            return this.setState([row], map[command])
        },
        async setState(rows, state) {
            const ids = rows.map((r) => r.id)
            try {
                const data = await apiPost(`/reports/${this.type}`, { action: 'state', state, ids })
                if (!data.success) return notice(data.msg || 'Update failed.', 'error')
                rows.forEach((row) => {
                    const target = this.rows.find((r) => r.id === row.id)
                    if (target) target.state = state
                })
                this.clearSelection()
                notice(data.msg)
            } catch (err) {
                reportError(err, 'Update failed.')
            }
        },
        async removeReports(rows) {
            const ids = rows.map((r) => r.id)
            try {
                await confirmAction(
                    `Permanently delete ${ids.length} report(s)? This cannot be undone.`,
                    'Delete reports'
                )
            } catch {
                return
            }
            try {
                const data = await apiPost(`/reports/${this.type}`, { action: 'delete', ids })
                if (!data.success) return notice(data.msg || 'Deletion failed.', 'error')
                this.rows = this.rows.filter((r) => !ids.includes(r.id))
                this.clearSelection()
                notice(data.msg)
                this.$emit('changed')
            } catch (err) {
                reportError(err, 'Deletion failed.')
            }
        },
        async banCreator(row) {
            const o = row.offences || { total: 0, gamename: 0, avatar: 0 }
            try {
                await confirmAction(
                    `Ban "${row.creator.username}"? ` +
                        `${o.total} report(s) in total — ${o.gamename} table name, ${o.avatar} avatar. ` +
                        'All open reports against this player will be closed as "creator banned".',
                    'Ban player',
                    { confirmButtonText: 'Ban player', confirmButtonClass: 'el-button--danger' }
                )
            } catch {
                return
            }
            try {
                const data = await apiPost(`/banlist/${row.creator.player_id}`, {
                    action: 'ban',
                    resolve_reports: 1,
                })
                if (!data.success) return notice(data.msg || 'Ban failed.', 'error')
                notice(`${row.creator.username} banned — ${data.resolved_reports ?? 0} report(s) closed.`)
                this.$emit('changed')
                this.load()
            } catch (err) {
                reportError(err, 'Ban failed.')
            }
        },
        async unbanCreator(row) {
            try {
                const data = await apiPost(`/banlist/${row.creator.player_id}`, { action: 'unban' })
                if (!data.success) return notice(data.msg || 'Unban failed.', 'error')
                notice(`${row.creator.username} unbanned.`)
                this.$emit('changed')
                this.load()
            } catch (err) {
                reportError(err, 'Unban failed.')
            }
        },
    },
}
</script>
