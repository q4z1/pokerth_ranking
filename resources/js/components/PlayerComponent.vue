<template>
    <div class="player">
        <div class="inner" v-if="player">
            <ul class="topiclist">
                <li class="header">
                    <dl class="row-item">
                        <dt><div class="list-inner">
                            {{ player.username }}
                            <span class="icons">
                                &nbsp;&nbsp;
                                <el-tooltip v-if="player.gender" effect="dark" placement="top-start" :content="(player.gender === 'f') ? 'female' : 'male'">
                                    <span class="gender">
                                        <i v-if="player.gender === 'f'" class="icon fa-female gender" />
                                        <i v-else-if="player.gender === 'm'" class="icon fa-male gender" />
                                    </span>
                                </el-tooltip>
                                <el-tooltip v-if="player.country_iso && country && country.svg != 'n/a'" effect="dark" placement="top-start" :content="country.title">
                                    <span class="flag">
                                        <img data-toggle="tooltip" :title="country.title" :src="'/images/flags/' + country.svg + '.svg'" />
                                    </span>
                                </el-tooltip>
                            </span>
                        </div></dt>
                    </dl>
                </li>
            </ul>
            <ul class="topiclist forums">
		        <li class="row">
                    <div class="list-inner">
                        <el-row class="data" :gutter="16" style="align-items: stretch;">
                            <el-col class="data" :xs="24" :md="(avatar) ? 18 : 24">
                                <el-card class="box-card">
                                    <template #header>
                                        <span>Season Data</span>
                                    </template>
                                    <el-row v-if="player.ranking.season_games">
                                        <el-col :span="12">Ranking position:</el-col>
                                        <el-col :span="12">{{ pos }}</el-col>
                                    </el-row>
                                    <el-row>
                                        <el-col :span="12">Final Score:</el-col>
                                        <el-col :span="12">{{ (player.ranking.final_score/100) }}</el-col>
                                    </el-row>
                                    <el-row>
                                        <el-col :span="12">Ø Points:</el-col>
                                        <el-col :span="12">{{ (player.ranking.average_score/100) }}</el-col>
                                    </el-row>
                                    <el-row>
                                        <el-col :span="12">Total Points:</el-col>
                                        <el-col :span="12">{{ player.ranking.points_sum }}</el-col>
                                    </el-row>
                                    <el-row v-if="player.ranking.season_games">
                                        <el-col :span="12">Last 5 games place:</el-col>
                                        <el-col :span="12">{{ last5.join(', ') }}</el-col>
                                    </el-row>
                                    <el-row>
                                        <el-col :span="12">Number of games:</el-col>
                                        <el-col :span="12">{{ player.ranking.season_games }}</el-col>
                                    </el-row>
                                </el-card>
                            </el-col>
                            <el-col :xs="0" :md="6" v-if="avatar" class="avatar" style="display: flex;">
                                <el-card class="box-card avatar-card">
                                    <img :src="avatar" :alt="player.username" :title="player.username" />
                                </el-card>
                            </el-col>
                            <el-col :xs="24" class="games" :md="24" v-else-if="games && games.length">
                                <el-card v-if="player.ranking.season_games" class="box-card fix">
                                    <template #header>
                                        <span>Season Games</span>
                                    </template>
                                    <div class="list">
                                        <el-row v-for="g in games" :key="g.game_idgame" class="game">
                                            <el-col>
                                                <el-row>
                                                    <el-col>
                                                        <el-collapse>
                                                            <el-collapse-item :title="g.game.name">
                                                                <game-component :gameid="g.game_idgame"></game-component>
                                                            </el-collapse-item>
                                                        </el-collapse>
                                                    </el-col>
                                                </el-row>
                                                <el-row>
                                                    <el-col class="date">{{ g.game.start_time }} - {{ g.game.end_time }}</el-col>
                                                </el-row>
                                                <el-row>
                                                    <el-col class="divider"><hr /></el-col>
                                                </el-row>
                                            </el-col>
                                        </el-row>
                                        <div ref="infiniteSentinel1" class="infinite-sentinel"></div>
                                    </div>
                                </el-card>
                            </el-col>
                        </el-row>
                        <el-row v-if="avatar && games && games.length" class="games">
                            <el-col>
                                <el-card class="box-card fix">
                                    <template #header>
                                        <span>Season Games</span>
                                    </template>
                                    <div class="list">
                                        <el-row v-for="g in games" :key="g.game_idgame" class="game">
                                            <el-col>
                                                <el-row>
                                                    <el-col>
                                                        <el-collapse>
                                                            <el-collapse-item :title="g.game.name">
                                                                <game-component :gameid="g.game_idgame"></game-component>
                                                            </el-collapse-item>
                                                        </el-collapse>
                                                    </el-col>
                                                </el-row>
                                                <el-row>
                                                    <el-col class="date">{{ g.game.start_time }} - {{ g.game.end_time }}</el-col>
                                                </el-row>
                                                <el-row>
                                                    <el-col class="divider"><hr /></el-col>
                                                </el-row>
                                            </el-col>
                                        </el-row>
                                        <div ref="infiniteSentinel2" class="infinite-sentinel"></div>
                                    </div>
                                </el-card>
                            </el-col>
                        </el-row>
                        <el-row v-if="player.ranking.season_games" class="stats">
                            <el-col>
                                <el-card class="box-card">
                                    <template #header>
                                        <span>Season Stats</span>
                                    </template>
                                    <el-table :data="stats" style="width: 100%">
                                        <el-table-column
                                            label="Position">
                                        </el-table-column>
                                        <el-table-column v-for="(stat, index) in stats[0]"
                                            :key="index"
                                            :prop="index"
                                            :label="index"
                                            width="60px">
                                        </el-table-column>
                                    </el-table>
                                    <hr />
                                    <div class="chart-container">
                                        <PieChart :chartData="games_chart"/>
                                    </div>
                                    <hr />
                                    <div class="chart-container">
                                        <BarChart :chartData="barChartData" :options="barChartOptions"/>
                                    </div>
                                </el-card>
                            </el-col>
                        </el-row>
                        <el-row v-if="seasons && seasons.length">
                            <el-col>
                                <el-card class="box-card fix seasons">
                                    <template #header>
                                        <span>Season Results</span>
                                    </template>
                                    <div class="list">
                                        <el-row v-for="season in seasons" :key="season" class="season">
                                            <el-col>
                                                <season-component :season="season" :playerid="player.player_id"></season-component>
                                            </el-col>
                                        </el-row>
                                    </div>
                                </el-card>
                            </el-col>
                        </el-row>
                    </div>
		        </li>
            </ul>
        </div>
        <div class="inner" v-else>
            <ul class="topiclist">
                <li class="header">
                    <dl class="row-item">
                        <dt><div class="list-inner">Player search</div></dt>
                    </dl>
                </li>
            </ul>
            <ul class="topiclist forums">
		        <li class="row">
                    <div class="list-inner">
                        <el-select
                            v-model="value"
                            multiple
                            filterable
                            remote
                            reserve-keyword
                            placeholder="Username"
                            :remote-method="searchPlayer"
                            :loading="loading"
                            @change="getSelectedPlayer">
                            <el-option
                                v-for="item in players"
                                :key="item.player_id"
                                :label="item.username"
                                :value="item.player_id">
                            </el-option>
                        </el-select>
                    </div>
		        </li>
            </ul>
        </div>
    </div>
</template>
<script>
    import PieChart from './PieChart.vue'
    import BarChartComponent from './BarChartComponent.vue'
    import { PLACEMENT_COLORS, CHART_TEXT_COLOR, CHART_GRID_COLOR } from '../chartColors.js'

    export default {
        components: { PieChart, BarChart: BarChartComponent },
        data: function() { 
            return {
                player: false,
                players: [],
                last5: false,
                pos: false,
                games: false,
                stats: false,
                games_chart: null,
                seasons: null,
                err: false,
                modalVisible: false,
                loading: false,
                loadingMore: false,
                value: [],
                infiniteObserver: null,
            }
        },
        computed: {
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
            barChartOptions() {
                return {
                    scales: {
                        x: { ticks: { color: CHART_TEXT_COLOR }, grid: { color: CHART_GRID_COLOR } },
                        y: { ticks: { color: CHART_TEXT_COLOR }, grid: { color: CHART_GRID_COLOR } },
                    },
                    plugins: { legend: { labels: { color: CHART_TEXT_COLOR } } },
                }
            },
            country: function () {
                if (!this.player || !this.player.country_iso || !window.countries) return null
                return window.countries.find(obj => obj.png === this.player.country_iso.toLowerCase()) || null
            },
            avatar: function () {
                let avatar = false
                if(this.player.avatar_hash != ''){
                    avatar = '/images/avatars/game/' + this.player.avatar_hash + '.' + this.player.avatar_mime
                }
                return avatar
            }
        },
        mounted() {
            this.getPlayer()
            this.$nextTick(() => {
                const sentinels = [this.$refs.infiniteSentinel1, this.$refs.infiniteSentinel2].filter(Boolean)
                if (sentinels.length) {
                    this.infiniteObserver = new IntersectionObserver((entries) => {
                        if (entries.some(e => e.isIntersecting) && this.games && this.games.length) {
                            this.loadMoreGames()
                        }
                    }, { threshold: 0.1 })
                    sentinels.forEach(s => this.infiniteObserver.observe(s))
                }
            })
        },
        beforeUnmount() {
            if (this.infiniteObserver) {
                this.infiniteObserver.disconnect()
            }
        },
        methods: {
            getPlayer: function(event){
                let param = 'player_id'
                let value = null
                let urlParams = new URLSearchParams(window.location.search)
                if(urlParams.has('u')){
                    param = 'username'
                    value = urlParams.get('u')
                }else{
                    param = 'player_id'
                    value = urlParams.get('p')
                }
                axios.get('/pthranking/player/show?' + param + '=' + value)
                    .then(res => {
                        if(res.data.status){
                            this.player = res.data.player
                            this.last5 = res.data.last5
                            this.pos = res.data.pos
                            this.games = res.data.games
                            this.stats = res.data.stats
                            this.games_chart = res.data.bar_stats
                            this.seasons = res.data.seasons.sort().reverse()
                            this.err = false
                        }else{
                            this.err = res.data.msg
                            this.player = false
                        }
                    }).catch(err => {
                        this.err = err
                        this.player = false
                })
            },
            async searchPlayer(query) {
                if (query !== '' && query.length > 2) {
                    this.loading = true;
                    let queryInfo = new FormData()
                    queryInfo.append('username', query)
                    const res = await axios.post('/pthranking/player/search', queryInfo);
                    console.log(res.data)
                    if(typeof res.data.success !== 'undefined' && res.data.success === true)
                    {
                        this.players = res.data.players
                    }
                    this.loading = false
                }else{
                    this.players = []
                }
            },
            getSelectedPlayer: function(player_id){
                player_id = player_id[0]
                let param = 'player_id'
                axios.get('/pthranking/player/show?' + param + '=' + player_id)
                .then(res => {
                    if(res.data.status){
                        this.player = res.data.player
                        this.last5 = res.data.last5
                        this.pos = res.data.pos
                        this.games = res.data.games
                        this.stats = res.data.stats
                        this.games_chart = res.data.bar_stats
                        this.err = false
                    }else{
                        this.err = res.data.msg
                        this.player = false
                    }
                }).catch(err => {
                    this.err = err
                    this.player = false
                })
            },
            tipHover: function(evt){ /* tooltip removed – jQuery dependency dropped */ },
            tipLeave: function(evt){ /* tooltip removed – jQuery dependency dropped */ },
            async loadMoreGames() {
                if (this.loadingMore) return
                this.loadingMore = true
                try {
                    const response = await axios.get(
                        '/pthranking/player/games/get?l=' + this.games.length + '&p=' + this.player.player_id
                    )
                    if (typeof response.data === 'object' && response.data.length) {
                        response.data.forEach(game => this.games.push(game))
                    } else if (this.infiniteObserver) {
                        // No more games – stop observing
                        this.infiniteObserver.disconnect()
                        this.infiniteObserver = null
                    }
                } finally {
                    this.loadingMore = false
                }
            },
            isNumeric: function(str) {
                if (typeof str != "string") return false
                return !isNaN(str) && !isNaN(parseFloat(str))
            },
        }
    }
</script>
<style>
/* Global player styles are in resources/css/pth.css */
.seasons { min-height: 350px; }
.player .fix .el-card__body { min-height: 300px; max-height: 300px; overflow-y: auto; }
.player .games .list .game .date { font-size: 0.7em; }
.player .avatar .el-card img { max-width: 175px; width: 100%; }
.player .avatar-card { width: 100%; display: flex; flex-direction: column; }
.player .avatar-card .el-card__body { flex: 1; display: flex; justify-content: center; align-items: center; }
.player span.icons { display: inline-flex; align-items: center; gap: 6px; vertical-align: middle; }
.player span.icons span.flag img { height: 20px; vertical-align: middle; }
.infinite-sentinel { height: 1px; }
.chart-container { position: relative; height: 300px; width: 100%; }
/* el-card + el-collapse + el-table transparent */
.player .el-card,
.player .el-card__header,
.player .el-card__body { background-color: transparent !important; color: var(--pth-text) !important; border-color: var(--pth-border) !important; }
.player .el-collapse,
.player .el-collapse-item__header,
.player .el-collapse-item__wrap,
.player .el-collapse-item__content { background-color: transparent !important; color: var(--pth-text) !important; border-color: var(--pth-border) !important; }
.player .el-table,
.player .el-table__inner-wrapper,
.player .el-table__header-wrapper,
.player .el-table__body-wrapper,
.player .el-scrollbar,
.player .el-scrollbar__wrap,
.player .el-scrollbar__view,
.player .el-table th,
.player .el-table tr,
.player .el-table td { background-color: transparent !important; }
.player .el-table th.el-table__cell,
.player .el-table td.el-table__cell { color: var(--pth-text); border-bottom-color: var(--pth-border) !important; }

.player .list-inner > * + * { margin-top: 16px; }
.player .el-col.games { margin-top: 16px; }
</style>
