<template>
    <admin-panel
        title="Game-Server alerts"
        subtitle="from gameserver:attack-check (every 5 min, 30 min window) – game-server time (CET/CEST)"
        :count="rows.length"
        :loading="loading"
        @refresh="load"
    >
        <el-table
            v-loading="loading"
            :data="rows"
            row-key="id"
            empty-text="No alerts – nothing suspicious found so far."
        >
            <el-table-column type="expand">
                <template #default="{ row }">
                    <div class="gsalert__expand">
                        <div class="admin-muted">Window {{ row.window_from }} – {{ row.ts }} · rule <code>{{ row.rule }}</code></div>
                        <pre v-if="row.samples && row.samples.length" class="gsalert__samples">{{ row.samples.join('\n') }}</pre>
                        <div v-if="isProxy(row.ip)" class="admin-muted">
                            10.7.7.160 is the /pthlive webclient proxy – the real IPs are only in nginx <code>pokerth_access.log</code>.
                        </div>
                    </div>
                </template>
            </el-table-column>
            <el-table-column label="Time" width="170">
                <template #default="{ row }">
                    <span :class="{ 'gsalert__new': row.id > seenId }">{{ row.ts }}</span>
                </template>
            </el-table-column>
            <el-table-column label="Severity" width="110">
                <template #default="{ row }">
                    <el-tag :type="row.severity === 'critical' ? 'danger' : 'warning'" disable-transitions size="small">
                        {{ row.severity }}
                    </el-tag>
                </template>
            </el-table-column>
            <el-table-column prop="title" label="Finding" min-width="200" />
            <el-table-column label="IP" width="160">
                <template #default="{ row }">{{ row.ip || 'all' }}</template>
            </el-table-column>
            <el-table-column prop="detail" label="Detail" min-width="320" />
        </el-table>
    </admin-panel>
</template>

<script>
import AdminPanel from './AdminPanel.vue'
import { apiGet, notice, reportError } from '../admin/adminUtils.js'

const PROXY_IPS = ['10.7.7.160', '::ffff:10.7.7.160']

export default {
    name: 'GameServerAlerts',
    components: { AdminPanel },
    props: {
        /** Höchste gesehene ID beim Öffnen – neuere Zeilen bleiben markiert, auch nachdem das Badge verschwindet. */
        seenId: { type: Number, default: 0 },
    },
    data() {
        return { rows: [], loading: false }
    },
    mounted() {
        this.load()
    },
    methods: {
        isProxy(ip) {
            return PROXY_IPS.includes(ip)
        },
        async load() {
            this.loading = true
            try {
                const data = await apiGet('/gameserver/alerts')
                if (data.success) this.rows = data.list
                else notice(data.msg || 'Could not load the alerts.', 'warning')
            } catch (err) {
                reportError(err, 'Could not load the alerts.')
            } finally {
                this.loading = false
            }
        },
    },
}
</script>

<style scoped>
.gsalert__expand {
    padding: 4px 16px 8px 48px;
}
.gsalert__samples {
    margin: 8px 0;
    padding: 8px 10px;
    font-size: 12px;
    white-space: pre-wrap;
    word-break: break-all;
    background: var(--el-fill-color-light);
    border-radius: 4px;
}
.gsalert__new {
    font-weight: 600;
}
</style>
