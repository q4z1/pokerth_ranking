<template>
    <section class="admin-panel">
        <header class="admin-panel__head">
            <div class="admin-panel__title">
                <h3>{{ title }}</h3>
                <el-tag v-if="count !== null" type="info" round size="small" disable-transitions>
                    {{ count }}
                </el-tag>
                <span v-if="subtitle" class="admin-panel__subtitle">{{ subtitle }}</span>
            </div>
            <div class="admin-panel__tools">
                <slot name="toolbar" />
                <el-tooltip content="Reload" placement="top">
                    <el-button :icon="Refresh" circle :loading="loading" @click="$emit('refresh')" />
                </el-tooltip>
            </div>
        </header>

        <div v-if="$slots.bulk" class="admin-panel__bulk">
            <slot name="bulk" />
        </div>

        <div class="admin-panel__body">
            <slot />
        </div>

        <footer v-if="total > pageSize" class="admin-panel__foot">
            <el-pagination
                background
                layout="total, sizes, prev, pager, next"
                :page-sizes="[25, 50, 100, 250]"
                :page-size="pageSize"
                :current-page="page"
                :total="total"
                @update:current-page="$emit('update:page', $event)"
                @update:page-size="$emit('update:pageSize', $event)"
            />
        </footer>
    </section>
</template>

<script>
import { Refresh } from '@element-plus/icons-vue'

export default {
    name: 'AdminPanel',
    props: {
        title: { type: String, required: true },
        subtitle: { type: String, default: '' },
        count: { type: Number, default: null },
        loading: { type: Boolean, default: false },
        total: { type: Number, default: 0 },
        page: { type: Number, default: 1 },
        pageSize: { type: Number, default: 25 },
    },
    emits: ['refresh', 'update:page', 'update:pageSize'],
    data() {
        return { Refresh }
    },
}
</script>
