<template>
    <div class="season">
        <div v-if="player && player.ranking.season_games">
            <el-collapse>
                <el-collapse-item :title="season">
                    <el-row>
                        <el-col class="data" :md="24">
                            <el-row>
                                <el-col :span="12">Ranking position:</el-col>
                                <el-col :span="12">{{ pos }}</el-col>
                            </el-row>
                            <el-row>
                                <el-col :span="12">Final Score:</el-col>
                                <el-col :span="12">{{ (player.ranking.final_score / 100) }}</el-col>
                            </el-row>
                            <el-row>
                                <el-col :span="12">Ø Points:</el-col>
                                <el-col :span="12">{{ (player.ranking.average_score / 100) }}</el-col>
                            </el-row>
                            <el-row>
                                <el-col :span="12">Total Points:</el-col>
                                <el-col :span="12">{{ player.ranking.points_sum }}</el-col>
                            </el-row>
                            <el-row>
                                <el-col :span="12">Number of games:</el-col>
                                <el-col :span="12">{{ player.ranking.season_games }}</el-col>
                            </el-row>
                        </el-col>
                    </el-row>
                    <el-row v-if="stats">
                        <el-col>
                            <hr />
                            <el-row>
                                <el-col>
                                    <el-table :data="stats" style="width: 100%">
                                        <el-table-column label="Position">
                                        </el-table-column>
                                        <el-table-column v-for="(stat, index) in stats[0]" :key="index" :prop="index"
                                            :label="index" width="60px">
                                        </el-table-column>
                                    </el-table>
                                </el-col>
                            </el-row>
                            <el-row>
                                <el-col>
                                    <hr />
                                    <PieChart :chartData="games_chart" />
                                    <hr />
                                    <BarChart :chartData="games_chart" />
                                </el-col>
                            </el-row>
                        </el-col>
                    </el-row>
                </el-collapse-item>
            </el-collapse>
        </div>
    </div>
</template>
<script>
export default {
    props: ['season', 'playerid'],
    components: {},
    data: function () {
        return {
            player: false,
            pos: false,
            stats: false,
            games_chart: null,
            err: false,
        }
    },
    mounted() {
        this.getSeason()
    },
    methods: {
        getSeason: function (event) {
            axios.get('/pthranking/player/season/get/' + this.playerid + '/' + this.season)
                .then(res => {
                    if (res.data.status) {
                        this.player = res.data.player
                        this.pos = res.data.pos
                        this.stats = res.data.stats
                        this.games_chart = res.data.bar_stats
                        this.err = false
                    } else {
                        this.err = res.data.msg
                        this.player = false
                    }
                }).catch(err => {
                    this.err = err
                    this.player = false
                })
        },
    }
}
</script>
<style lang="scss">
.season {
    canvas {
        height: 250px !important;
    }
}
</style>