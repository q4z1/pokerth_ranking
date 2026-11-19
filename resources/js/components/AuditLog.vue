<template>
    <admin-panel
        title="Audit log"
        subtitle="Who did what – logins, bans, mutes, kickbans, report actions, adverts"
        :count="total"
        :loading="loading"
        :total="total"
        :page="page"
        :page-size="pageSize"
        @refresh="load"
        @update:page="onPage"
        @update:pageSize="onPageSize"
    >
        <template #toolbar>
            <el-input
                v-model="q"
                class="admin-toolbar__search"
                placeholder="Target nick or ID"
                :prefix-icon="Search"
                clearable
            />
            <el-select v-model="action" class="admin-toolbar__select" placeholder="Action" clearable>
                <el-option v-for="(label, key) in actions" :key="key" :label="label" :value="key" />
            </el-select>
            <el-select v-model="admin" class="admin-toolbar__select" placeholder="Admin" clearable>
                <el-option v-for="name in admins" :key="name" :label="name" :value="name" />
            </el-select>
        </template>

        <el-table v-loading="loading" class="admin-table" :data="rows" row-key="id" stripe :empty-text="emptyText">
            <el-table-column label="Time" width="150">
                <template #default="{ row }">{{ formatDateTime(row.created_at) }}</template>
            </el-table-column>
            <el-table-column label="Admin" min-width="120">
                <template #default="{ row }">{{ row.admin || '—' }}</template>
            </el-table-column>
            <el-table-column label="Action" width="170">
                <template #default="{ row }">
                    <el-tag :type="actionType(row)" size="small" disable-transitions>
                        {{ actions[row.action] || row.action }}
                    </el-tag>
                    <el-tag v-if="!row.success" type="danger" size="small" effect="plain" disable-transitions>
                        failed
                    </el-tag>
                </template>
            </el-table-column>
            <el-table-column label="Target" min-width="150">
                <template #default="{ row }">
                    <span v-if="row.target_name">{{ row.target_name }}</span>
                    <span v-if="row.target_player_id" class="admin-muted"> #{{ row.target_player_id }}</span>
                    <span v-if="!row.target_name && !row.target_player_id" class="admin-muted">—</span>
                </template>
            </el-table-column>
            <el-table-column label="Details" min-width="280">
                <template #default="{ row }">
                    <span class="audit-details">{{ detailText(row) }}</span>
                </template>
            </el-table-column>
            <el-table-column label="IP" width="140">
                <template #default="{ row }"><code class="admin-code">{{ row.ip || '—' }}</code></template>
            </el-table-column>
        </el-table>
    </admin-panel>
</template>

<script>
import { Search } from '@element-plus/icons-vue'
import AdminPanel from './AdminPanel.vue'
import { apiGet, formatDateTime, notice, reportError } from '../admin/adminUtils.js'

/** Eingriffe gegen Spieler rot, Rücknahmen grün, Login-Kram grau. */
const DANGER = ['ban', 'kickban', 'player_delete', 'mute', 'avatar_blacklist', 'login_failed']
const SUCCESS = ['unban', 'unmute', 'report_unarchive']
const INFO = ['login', 'logout']

export default {
    name: 'AuditLog',
    components: { AdminPanel },
    data() {
        return {
            rows: [],
            total: 0,
            page: 1,
            pageSize: 50,
            loading: false,
            q: '',
            action: '',
            admin: '',
            actions: {},
            admins: [],
            debounceTimer: null,
            Search,
        }
    },
    computed: {
        emptyText() {
            return this.loading ? 'Loading…' : 'No admin actions logged yet.'
        },
    },
    watch: {
        q() {
            clearTimeout(this.debounceTimer)
            this.debounceTimer = setTimeout(() => this.reload(), 350)
        },
        action() {
            this.reload()
        },
        admin() {
            this.reload()
        },
    },
    mounted() {
        this.load()
    },
    beforeUnmount() {
        clearTimeout(this.debounceTimer)
    },
    methods: {
        formatDateTime,
        reload() {
            this.page = 1
            this.load()
        },
        onPage(page) {
            this.page = page
            this.load()
        },
        onPageSize(size) {
            this.pageSize = size
            this.reload()
        },
        actionType(row) {
            if (DANGER.includes(row.action)) return 'danger'
            if (SUCCESS.includes(row.action)) return 'success'
            if (INFO.includes(row.action)) return 'info'
            return 'warning'
        },
        /** Details kompakt als "key: value · key: value"; Texte (Notice/PM) zuerst und in Anführungszeichen. */
        detailText(row) {
            const d = row.details
            if (!d || typeof d !== 'object') return ''
            const parts = []
            if (d.text) parts.push(`“${d.text}”`)
            for (const [key, value] of Object.entries(d)) {
                if (key === 'text' || value === null || value === '') continue
                const v = Array.isArray(value) ? value.join(', ') : typeof value === 'object' ? JSON.stringify(value) : value
                parts.push(`${key.replace(/_/g, ' ')}: ${v}`)
            }
            return parts.join(' · ')
        },
        async load() {
            this.loading = true
            try {
                const params = { page: this.page, per_page: this.pageSize }
                if (this.q.trim()) params.q = this.q.trim()
                if (this.action) params.action = this.action
                if (this.admin) params.admin = this.admin
                const data = await apiGet('/audit', params)
                if (data.success) {
                    this.rows = data.list
                    this.total = data.total
                    this.actions = data.actions || {}
                    this.admins = data.admins || []
                } else {
                    notice(data.msg || 'Could not load the audit log.', 'warning')
                }
            } catch (err) {
                reportError(err, 'Could not load the audit log.')
            } finally {
                this.loading = false
            }
        },
    },
}
</script>

<style scoped>
.audit-details {
    font-size: 12px;
    word-break: break-word;
}
</style>
