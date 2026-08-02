<template>
    <admin-panel
        title="Adverts"
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
                placeholder="Search position or content"
                :prefix-icon="Search"
                clearable
            />
            <el-button type="primary" :icon="Plus" @click="openDialog()">New advert</el-button>
        </template>

        <el-table
            v-loading="loading"
            class="admin-table"
            :data="pagedRows"
            row-key="id"
            stripe
            :empty-text="emptyText"
            :default-sort="{ prop: 'position', order: 'ascending' }"
            @sort-change="onSortChange"
        >
            <el-table-column prop="id" label="ID" width="80" sortable="custom" />
            <el-table-column prop="position" label="Position" width="130" sortable="custom" />
            <el-table-column prop="order" label="Order" width="100" align="right" sortable="custom" />
            <el-table-column label="Runtime" width="220">
                <template #default="{ row }">
                    <span :class="{ 'admin-muted': !isLive(row) }">
                        {{ formatDate(row.start) }} – {{ formatDate(row.end) }}
                    </span>
                    <el-tag v-if="isLive(row)" size="small" type="success" disable-transitions>live</el-tag>
                    <el-tag v-else size="small" type="info" disable-transitions>inactive</el-tag>
                </template>
            </el-table-column>
            <el-table-column label="Content" min-width="260">
                <template #default="{ row }">
                    <div class="admin-advert-preview" v-html="row.content"></div>
                </template>
            </el-table-column>
            <el-table-column label="Action" width="170" align="right">
                <template #default="{ row }">
                    <el-button size="small" :icon="Edit" @click="openDialog(row)">Edit</el-button>
                    <el-button size="small" type="danger" :icon="Delete" @click="remove(row)" />
                </template>
            </el-table-column>
        </el-table>

        <el-dialog v-model="dialogVisible" :title="form.id ? 'Edit advert' : 'New advert'" width="640px">
            <el-form :model="form" label-width="110px" label-position="left">
                <el-form-item label="Position">
                    <el-autocomplete
                        v-model="form.position"
                        :fetch-suggestions="positionSuggestions"
                        placeholder="home"
                        style="width: 100%"
                    />
                </el-form-item>
                <el-form-item label="Order">
                    <el-input-number v-model="form.order" :min="0" :max="127" controls-position="right" />
                </el-form-item>
                <el-form-item label="Runtime">
                    <el-date-picker
                        v-model="form.range"
                        type="daterange"
                        value-format="YYYY-MM-DD"
                        start-placeholder="Start"
                        end-placeholder="End"
                        unlink-panels
                    />
                </el-form-item>
                <el-form-item label="Content">
                    <el-input v-model="form.content" type="textarea" :rows="8" placeholder="<p>…</p>" />
                </el-form-item>
                <el-form-item label="Preview">
                    <div class="admin-advert-preview admin-advert-preview--large" v-html="form.content"></div>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="dialogVisible = false">Cancel</el-button>
                <el-button type="primary" :loading="saving" @click="save">Save</el-button>
            </template>
        </el-dialog>
    </admin-panel>
</template>

<script>
import { Delete, Edit, Plus, Search } from '@element-plus/icons-vue'
import AdminPanel from './AdminPanel.vue'
import { apiGet, apiPost, confirmAction, formatDate, listMixin, notice, reportError } from '../admin/adminUtils.js'

const emptyForm = () => ({ id: null, position: 'home', order: 0, content: '', range: [] })

export default {
    name: 'Adverts',
    components: { AdminPanel },
    mixins: [listMixin],
    data() {
        return {
            dialogVisible: false,
            saving: false,
            form: emptyForm(),
            sortProp: 'position',
            sortOrder: 'ascending',
            Delete,
            Edit,
            Plus,
            Search,
        }
    },
    computed: {
        subtitle() {
            return `${this.rows.filter(this.isLive).length} currently live`
        },
        emptyText() {
            return this.loading ? 'Loading…' : 'No adverts configured.'
        },
        positions() {
            return [...new Set(this.rows.map((r) => r.position))].map((value) => ({ value }))
        },
    },
    mounted() {
        this.load()
    },
    methods: {
        formatDate,
        matches(row, term) {
            return (
                (row.position || '').toLowerCase().includes(term) ||
                (row.content || '').toLowerCase().includes(term)
            )
        },
        isLive(row) {
            const today = new Date().toISOString().slice(0, 10)
            return formatDate(row.start) <= today && formatDate(row.end) >= today
        },
        positionSuggestions(query, cb) {
            const term = (query || '').toLowerCase()
            cb(this.positions.filter((p) => p.value.toLowerCase().includes(term)))
        },
        async load() {
            this.loading = true
            try {
                const data = await apiGet('/adverts')
                this.rows = data.success ? data.list : []
                if (!data.success) notice(data.msg || 'Could not load adverts.', 'warning')
            } catch (err) {
                reportError(err, 'Could not load adverts.')
            } finally {
                this.loading = false
            }
        },
        openDialog(row = null) {
            this.form = row
                ? {
                      id: row.id,
                      position: row.position,
                      order: row.order,
                      content: row.content,
                      range: [formatDate(row.start), formatDate(row.end)],
                  }
                : emptyForm()
            this.dialogVisible = true
        },
        async save() {
            const [start, end] = this.form.range || []
            if (!this.form.position || !this.form.content || !start || !end) {
                return notice('Position, runtime and content are required.', 'warning')
            }
            this.saving = true
            try {
                const data = await apiPost('/adverts', {
                    action: this.form.id ? 'update' : 'create',
                    id: this.form.id,
                    position: this.form.position,
                    order: this.form.order ?? 0,
                    content: this.form.content,
                    start,
                    end,
                })
                if (!data.success) return notice(data.msg || 'Saving failed.', 'error')
                notice(data.msg)
                this.dialogVisible = false
                this.load()
            } catch (err) {
                reportError(err, 'Saving failed.')
            } finally {
                this.saving = false
            }
        },
        async remove(row) {
            try {
                await confirmAction(`Delete advert #${row.id} (${row.position})?`, 'Delete advert')
            } catch {
                return
            }
            try {
                const data = await apiPost('/adverts', { action: 'delete', id: row.id })
                if (!data.success) return notice(data.msg || 'Deletion failed.', 'error')
                this.rows = this.rows.filter((r) => r.id !== row.id)
                notice(data.msg)
            } catch (err) {
                reportError(err, 'Deletion failed.')
            }
        },
    },
}
</script>
