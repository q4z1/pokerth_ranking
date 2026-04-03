<template>
    <div class="season">
        <div v-if="player && player.ranking.season_games">
            <div class="season-collapse">
                <div class="season-collapse__header" @click="open = !open">
                    <span>{{ season }}</span>
                </div>
                <div v-show="open" class="season-collapse__body">
                    <!-- Summary stats -->
                    <el-descriptions :column="2" border class="season-desc">
                        <el-descriptions-item label="Ranking position">{{ pos }}</el-descriptions-item>
                        <el-descriptions-item label="Number of games">{{ player.ranking.season_games }}</el-descriptions-item>
                        <el-descriptions-item label="Final Score">{{ (player.ranking.final_score / 100) }}</el-descriptions-item>
                        <el-descriptions-item label="Ø Points">{{ (player.ranking.average_score / 100) }}</el-descriptions-item>
                        <el-descriptions-item label="Total Points">{{ player.ranking.points_sum }}</el-descriptions-item>
                    </el-descriptions>

                    <template v-if="stats">
                        <hr />
                        <!-- Placement table -->
                        <el-table :data="tableRows" style="width: 100%" size="default">
                            <el-table-column prop="label" label="" width="100" />
                            <el-table-column v-for="n in 10" :key="n" :prop="String(n)" :label="ordinal(n)" min-width="68" align="center" />
                        </el-table>

                        <!-- Charts side by side -->
                        <el-row :gutter="24" class="season-charts">
                            <el-col :xs="24" :md="10">
                                <div class="season-chart-box">
                                    <PieChart :chartData="games_chart" />
                                </div>
                            </el-col>
                            <el-col :xs="24" :md="14">
                                <div class="season-chart-box">
                                    <BarChartComponent :chartData="barChartData" />
                                </div>
                            </el-col>
                        </el-row>
                    </template>
                </div><!-- /season-collapse__body -->
            </div><!-- /season-collapse -->
        </div>
        <div v-else-if="player && !player.ranking.season_games" class="season-none">
            No data for this season.
        </div>
    </div>
</template>
<script>
import PieChart from './PieChart.vue'
import BarChartComponent from './BarChartComponent.vue'
import { PLACEMENT_COLORS } from '../chartColors.js'

export default {
    props: ['season', 'playerid'],
    components: { PieChart, BarChartComponent },
    data() {
        return {
            player: false,
            pos: false,
            stats: false,
            games_chart: null,
            err: false,
            open: false,
        }
    },
    computed: {
        tableRows() {
            if (!this.stats) return []
            return [
                { label: 'Count',   ...Object.fromEntries(Object.entries(this.stats[0]).map(([k,v]) => [String(k), v])) },
                { label: 'Percent', ...Object.fromEntries(Object.entries(this.stats[1]).map(([k,v]) => [String(k), v])) },
            ]
        },
        barChartData() {
            if (!this.games_chart) return { labels: [], datasets: [] }
            return {
                labels: ['1st','2nd','3rd','4th','5th','6th','7th','8th','9th','10th'],
                datasets: [{
                    label: 'Games',
                    backgroundColor: PLACEMENT_COLORS,
                    borderColor: PLACEMENT_COLORS,
                    borderWidth: 1,
                    data: this.games_chart,
                }],
            }
        },
    },
    mounted() {
        this.getSeason()
    },
    methods: {
        ordinal(n) {
            const s = ['th','st','nd','rd']
            const v = n % 100
            return n + (s[(v-20)%10] || s[v] || s[0])
        },
        getSeason() {
            axios.get('/pthranking/player/season/get/' + this.playerid + '/' + this.season)
                .then(res => {
                    if (res.data.status) {
                        this.player = res.data.player
                        this.pos    = res.data.pos
                        this.stats  = res.data.stats
                        this.games_chart = res.data.bar_stats
                        this.err = false
                    } else {
                        this.err   = res.data.msg
                        this.player = false
                    }
                }).catch(err => {
                    this.err    = err
                    this.player = false
                })
        },
    },
}
</script>
<style lang="scss">
.season {
    .season-desc {
        margin-bottom: 8px;
    }
    .season-charts {
        margin-top: 20px;
    }
    .season-chart-box {
        height: 380px;
        position: relative;
    }
    .season-none {
        padding: 8px 0;
        opacity: 0.6;
    }
    /* Custom collapse */
    .season-collapse__header {
        cursor: pointer;
        padding: 12px 16px;
        border-bottom: 1px solid var(--pth-border, rgba(255,255,255,0.1));
        font-weight: 500;
        user-select: none;
        &:hover {
            background: rgba(255,255,255,0.04);
        }
    }
    .season-collapse__body {
        padding: 16px;
    }
    /* Element Plus table transparent in dark mode */
    .el-table,
    .el-table tr,
    .el-table th.el-table__cell,
    .el-table td.el-table__cell {
        background-color: transparent !important;
    }
    .el-descriptions__body,
    .el-descriptions__label,
    .el-descriptions__content,
    .el-descriptions__cell {
        background-color: transparent !important;
    }
}
</style>