<template>
    <admin-panel
        title="Banlist"
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
            <el-autocomplete
                v-model="nickname"
                class="admin-toolbar__search"
                placeholder="Ban a player…"
                value-key="username"
                :fetch-suggestions="querySearch"
                clearable
                @select="handleNickSelect"
                @clear="banCandidate = null"
            />
            <el-button type="danger" :icon="CircleClose" :disabled="!banCandidate" @click="ban">Ban</el-button>
        </template>

        <el-table
            v-loading="loading"
            class="admin-table"
            :data="pagedRows"
            row-key="player_id"
            stripe
            :empty-text="emptyText"
            :default-sort="{ prop: 'username', order: 'ascending' }"
            @sort-change="onSortChange"
        >
            <el-table-column prop="player_id" label="ID" width="90" sortable="custom" />
            <el-table-column prop="username" label="Nickname" min-width="180" sortable="custom" />
            <el-table-column prop="total_reports" label="Reports" width="120" align="right" sortable="custom">
                <template #default="{ row }">
                    <el-tooltip v-if="row.total_reports" placement="top">
                        <template #content>
                            {{ row.gamename_reports }} table name · {{ row.avatar_reports }} avatar report(s)
                        </template>
                        <el-tag size="small" type="warning" effect="plain" disable-transitions>
                            {{ row.total_reports }}
                        </el-tag>
                    </el-tooltip>
                    <span v-else class="admin-muted">—</span>
                </template>
            </el-table-column>
            <el-table-column prop="created" label="Registered" width="150" sortable="custom">
                <template #default="{ row }">{{ formatDateTime(row.created) }}</template>
            </el-table-column>
            <el-table-column prop="last_login" label="Last login" width="150" sortable="custom">
                <template #default="{ row }">{{ formatDateTime(row.last_login) }}</template>
            </el-table-column>
            <el-table-column label="Action" width="190" align="right">
                <template #default="{ row }">
                    <el-button size="small" type="success" plain @click="unbanPlayer(row)">Unban</el-button>
                    <el-button size="small" type="danger" :icon="Delete" @click="deletePlayer(row)">Delete</el-button>
                </template>
            </el-table-column>
        </el-table>
    </admin-panel>
</template>

<script>
import { CircleClose, Delete, Search } from '@element-plus/icons-vue'
import AdminPanel from './AdminPanel.vue'
import { apiGet, apiPost, confirmAction, formatDateTime, listMixin, notice, reportError } from '../admin/adminUtils.js'

export default {
    name: 'BanList',
    components: { AdminPanel },
    mixins: [listMixin],
    emits: ['changed'],
    data() {
        return {
            nickname: '',
            banCandidate: null,
            sortProp: 'username',
            sortOrder: 'ascending',
            CircleClose,
            Delete,
            Search,
        }
    },
    computed: {
        subtitle() {
            const reported = this.rows.filter((r) => r.total_reports > 0).length
            return `${reported} of them with reports`
        },
        emptyText() {
            return this.loading ? 'Loading…' : 'No banned players.'
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
        async load() {
            this.loading = true
            try {
                const data = await apiGet('/banlist')
                this.rows = data.success ? data.list : []
                if (!data.success) notice(data.msg || 'Could not load the banlist.', 'warning')
            } catch (err) {
                reportError(err, 'Could not load the banlist.')
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
        handleNickSelect(item) {
            this.banCandidate = item
        },
        async ban() {
            if (!this.banCandidate) return
            try {
                await confirmAction(`Ban "${this.banCandidate.username}"?`, 'Ban player', {
                    confirmButtonText: 'Ban player',
                    confirmButtonClass: 'el-button--danger',
                })
            } catch {
                return
            }
            try {
                const data = await apiPost(`/banlist/${this.banCandidate.player_id}`, {
                    action: 'ban',
                    resolve_reports: 1,
                })
                if (!data.success) return notice(data.msg || 'Ban failed.', 'error')
                notice(`${this.banCandidate.username} added to the banlist.`)
                this.nickname = ''
                this.banCandidate = null
                this.$emit('changed')
                this.load()
            } catch (err) {
                reportError(err, 'Ban failed.')
            }
        },
        async unbanPlayer(row) {
            try {
                const data = await apiPost(`/banlist/${row.player_id}`, { action: 'unban' })
                if (!data.success) return notice(data.msg || 'Unban failed.', 'error')
                this.rows = this.rows.filter((r) => r.player_id !== row.player_id)
                notice(`${row.username} unbanned.`)
                this.$emit('changed')
            } catch (err) {
                reportError(err, 'Unban failed.')
            }
        },
        async deletePlayer(row) {
            try {
                await confirmAction(
                    `Permanently delete "${row.username}"? ` +
                        'This also removes the matching forum account and all ranking data. This cannot be undone.',
                    'Delete player',
                    { confirmButtonText: 'Delete', confirmButtonClass: 'el-button--danger' }
                )
            } catch {
                return
            }
            try {
                const data = await apiPost(`/banlist/${row.player_id}`, { action: 'delete' })
                if (!data.success) return notice(data.msg || 'Deletion failed.', 'error')
                this.rows = this.rows.filter((r) => r.player_id !== row.player_id)
                notice(`${row.username} deleted.`)
                this.$emit('changed')
            } catch (err) {
                reportError(err, 'Deletion failed.')
            }
        },
    },
}
</script>
