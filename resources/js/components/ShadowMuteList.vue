<template>
    <admin-panel
        title="Shadow mutes"
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
                placeholder="Search nickname"
                :prefix-icon="Search"
                clearable
            />
            <el-checkbox v-model="showExpired" label="Show expired" />
            <el-autocomplete
                v-model="nickname"
                class="admin-toolbar__search"
                placeholder="Shadow mute a player…"
                value-key="username"
                :fetch-suggestions="querySearch"
                clearable
                @select="muteCandidate = $event"
                @clear="muteCandidate = null"
            />
            <el-select v-model="duration" class="shadow-mute__duration">
                <el-option v-for="d in durations" :key="d.value" :label="d.label" :value="d.value" />
            </el-select>
            <el-button type="warning" :icon="MuteNotification" :disabled="!muteCandidate" @click="muteNew">
                Mute
            </el-button>
        </template>

        <el-table
            v-loading="loading"
            class="admin-table"
            :data="pagedRows"
            row-key="player_id"
            stripe
            :empty-text="emptyText"
            :default-sort="{ prop: 'remaining', order: 'descending' }"
            @sort-change="onSortChange"
        >
            <el-table-column prop="player_id" label="ID" width="90" sortable="custom" />
            <el-table-column prop="username" label="Nickname" min-width="180" sortable="custom">
                <template #default="{ row }">
                    {{ row.username }}
                    <el-tag v-if="row.banned" size="small" type="danger" effect="plain" disable-transitions>
                        banned
                    </el-tag>
                </template>
            </el-table-column>
            <el-table-column prop="total_reports" label="Reports" width="110" align="right" sortable="custom">
                <template #default="{ row }">
                    <el-tag v-if="row.total_reports" size="small" type="warning" effect="plain" disable-transitions>
                        {{ row.total_reports }}
                    </el-tag>
                    <span v-else class="admin-muted">—</span>
                </template>
            </el-table-column>
            <el-table-column prop="remaining" label="Muted until (UTC)" min-width="210" sortable="custom">
                <template #default="{ row }">
                    <template v-if="row.permanent">
                        <el-tag size="small" type="danger" disable-transitions>permanent</el-tag>
                    </template>
                    <template v-else>
                        {{ formatDateTime(row.until) }}
                        <span class="admin-muted">
                            · {{ row.muted ? formatRemaining(row.remaining) + ' left' : 'expired' }}
                        </span>
                    </template>
                </template>
            </el-table-column>
            <el-table-column prop="last_login" label="Last login" width="150" sortable="custom">
                <template #default="{ row }">{{ formatDateTime(row.last_login) }}</template>
            </el-table-column>
            <el-table-column label="Action" width="220" align="right">
                <template #default="{ row }">
                    <el-dropdown trigger="click" @command="(d) => mute(row, d)">
                        <el-button size="small" type="warning" plain>
                            {{ row.muted ? 'Change' : 'Re-mute' }}
                        </el-button>
                        <template #dropdown>
                            <el-dropdown-menu>
                                <el-dropdown-item v-for="d in durations" :key="d.value" :command="d.value">
                                    {{ d.label }} from now
                                </el-dropdown-item>
                            </el-dropdown-menu>
                        </template>
                    </el-dropdown>
                    <el-button v-if="row.muted" size="small" type="success" plain @click="unmute(row)">Unmute</el-button>
                    <el-button v-else size="small" plain @click="unmute(row)">Clear</el-button>
                </template>
            </el-table-column>
        </el-table>
    </admin-panel>
</template>

<script>
import { MuteNotification, Search } from '@element-plus/icons-vue'
import AdminPanel from './AdminPanel.vue'
import { apiGet, apiPost, confirmAction, formatDateTime, listMixin, notice, reportError } from '../admin/adminUtils.js'

/** Muss zu AdminController::SHADOW_MUTE_DURATIONS passen (+ 'permanent'). */
const DURATIONS = [
    { value: '1h', label: '1 hour' },
    { value: '1d', label: '1 day' },
    { value: '3d', label: '3 days' },
    { value: '7d', label: '7 days' },
    { value: '30d', label: '30 days' },
    { value: '90d', label: '90 days' },
    { value: 'permanent', label: 'Permanent' },
]

export default {
    name: 'ShadowMuteList',
    components: { AdminPanel },
    mixins: [listMixin],
    data() {
        return {
            nickname: '',
            muteCandidate: null,
            duration: '7d',
            durations: DURATIONS,
            showExpired: false,
            sortProp: 'remaining',
            sortOrder: 'descending',
            MuteNotification,
            Search,
        }
    },
    computed: {
        baseRows() {
            return this.showExpired ? this.rows : this.rows.filter((r) => r.muted)
        },
        subtitle() {
            const active = this.rows.filter((r) => r.muted).length
            return `${active} active · chat is silently dropped for everyone but the player, PMs unaffected`
        },
        emptyText() {
            return this.loading ? 'Loading…' : 'No shadow muted players.'
        },
    },
    watch: {
        showExpired() {
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
        formatRemaining(seconds) {
            const d = Math.floor(seconds / 86400)
            const h = Math.floor((seconds % 86400) / 3600)
            const m = Math.floor((seconds % 3600) / 60)
            if (d) return `${d}d ${h}h`
            if (h) return `${h}h ${m}m`
            return `${Math.max(m, 1)}m`
        },
        durationLabel(value) {
            return DURATIONS.find((d) => d.value === value)?.label.toLowerCase() ?? value
        },
        async load() {
            this.loading = true
            try {
                const data = await apiGet('/shadowmute')
                // Permanente Mutes beim Sortieren nach Restzeit ganz nach oben.
                this.rows = data.success
                    ? data.list.map((r) => ({ ...r, remaining: r.permanent ? Infinity : r.remaining }))
                    : []
                if (!data.success) notice(data.msg || 'Could not load the shadow mutes.', 'warning')
            } catch (err) {
                reportError(err, 'Could not load the shadow mutes.')
            } finally {
                this.loading = false
            }
        },
        async querySearch(queryString, cb) {
            if (!queryString || queryString.length < 2) return cb([])
            try {
                const data = await apiPost('/player/search', { username: queryString })
                cb(data.success ? data.players : [])
            } catch (err) {
                console.error(err)
                cb([])
            }
        },
        async muteNew() {
            if (!this.muteCandidate) return
            const ok = await this.mute(this.muteCandidate, this.duration)
            if (ok) {
                this.nickname = ''
                this.muteCandidate = null
            }
        },
        async mute(player, duration) {
            try {
                await confirmAction(
                    `Shadow mute "${player.username}" (${this.durationLabel(duration)}${duration === 'permanent' ? '' : ' from now'})?`,
                    'Shadow mute',
                    { confirmButtonText: 'Mute', confirmButtonClass: 'el-button--warning' }
                )
            } catch {
                return false
            }
            try {
                const data = await apiPost(`/shadowmute/${player.player_id}`, { action: 'mute', duration })
                if (!data.success) {
                    notice(data.msg || 'Mute failed.', 'error')
                    return false
                }
                notice(`${player.username} shadow muted.`)
                this.load()
                return true
            } catch (err) {
                reportError(err, 'Mute failed.')
                return false
            }
        },
        async unmute(row) {
            try {
                const data = await apiPost(`/shadowmute/${row.player_id}`, { action: 'unmute' })
                if (!data.success) return notice(data.msg || 'Unmute failed.', 'error')
                this.rows = this.rows.filter((r) => r.player_id !== row.player_id)
                notice(row.muted ? `${row.username} unmuted.` : `${row.username} removed from the list.`)
            } catch (err) {
                reportError(err, 'Unmute failed.')
            }
        },
    },
}
</script>

<style scoped>
.shadow-mute__duration {
    width: 120px;
}
</style>
