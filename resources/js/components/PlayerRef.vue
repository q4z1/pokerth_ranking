<template>
    <span v-if="!player" class="player-ref player-ref--empty">—</span>
    <span v-else class="player-ref">
        <span class="player-ref__name" :title="'player_id ' + player.player_id">
            {{ player.username || '#' + player.player_id }}
        </span>
        <el-tag v-if="!player.exists" size="small" type="info" disable-transitions>deleted</el-tag>
        <el-tag v-else-if="player.banned" size="small" type="danger" disable-transitions>banned</el-tag>
        <el-tooltip v-if="offences && offences.total > 1" placement="top">
            <template #content>
                {{ offences.gamename }} table name · {{ offences.avatar }} avatar report(s)
            </template>
            <el-tag size="small" :type="offenceType" effect="plain" disable-transitions>
                {{ offences.total }}&times;
            </el-tag>
        </el-tooltip>
    </span>
</template>

<script>
import { REPEAT_THRESHOLD } from '../admin/adminUtils.js'

export default {
    name: 'PlayerRef',
    props: {
        player: { type: Object, default: null },
        offences: { type: Object, default: null },
    },
    computed: {
        offenceType() {
            if (!this.offences) return 'info'
            if (this.offences.total >= REPEAT_THRESHOLD * 2) return 'danger'
            if (this.offences.total >= REPEAT_THRESHOLD) return 'warning'
            return 'info'
        },
    },
}
</script>
