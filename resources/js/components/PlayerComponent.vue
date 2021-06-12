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
                                <span class="gender" v-if="player.gender">
                                    <i v-if="player.gender === 'f'" class="icon fa-female gender" />
                                    <i v-else-if="player.gender === 'm'" class="icon fa-male gender" />
                                </span>
                                <span v-if="player.country_iso && country.svg != 'n/a'" class="flag">
                                    <img data-toggle="tooltip" :title="country.title" :src="'/images/flags/' + country.svg + '.svg'" />
                                </span>
                            </span>
                        </div></dt>
                    </dl>
                </li>
            </ul>
            <ul class="topiclist forums">
		        <li class="row">
                    <div class="list-inner">
                        <el-row class="data">
                            <el-col :xs="24" :md="6" v-if="avatar" class="avatar before">
                                <el-card class="box-card">
                                    <img :src="avatar" :alt="player.username" :title="player.username" />
                                </el-card>
                            </el-col>
                            <el-col class="data" :xs="24" :md="(avatar) ? 18 : 24">
                                <el-card class="box-card fix">
                                    <div slot="header" class="clearfix">
                                        <span>Season Data</span>
                                    </div>
                                    <el-row>
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
                                    <el-row>
                                        <el-col :span="12">Number of games:</el-col>
                                        <el-col :span="12">{{ player.ranking.season_games }}</el-col>
                                    </el-row>
                                    <el-row>
                                        <el-col :span="12">Last 5 games place:</el-col>
                                        <el-col :span="12">{{ last5.join(', ') }}</el-col>
                                    </el-row>
                                    <el-row>
                                        <el-col :span="12">Number of games:</el-col>
                                        <el-col :span="12">{{ player.ranking.season_games }}</el-col>
                                    </el-row>
                                </el-card>
                            </el-col>
                            <el-col :xs="24" :md="6" v-if="avatar" class="avatar after">
                                <el-card class="box-card">
                                    <img :src="avatar" :alt="player.username" :title="player.username" />
                                </el-card>
                            </el-col>
                            <el-col :xs="24" class="games" :md="24" v-else>
                                <el-card class="box-card fix">
                                    <div slot="header" class="clearfix">
                                        <span>Season Games</span>
                                    </div>
                                    <div v-if="games" class="list">
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
                                        <infinite-loading @infinite="infiniteHandler"></infinite-loading>
                                    </div>
                                </el-card>
                            </el-col>
                        </el-row>
                        <el-row v-if="avatar" class="games">
                            <el-col>
                                <el-card class="box-card fix">
                                    <div slot="header" class="clearfix">
                                        <span>Season Games</span>
                                    </div>
                                    <div v-if="games" class="list">
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
                                        <infinite-loading @infinite="infiniteHandler"></infinite-loading>
                                    </div>
                                </el-card>
                            </el-col>
                        </el-row>
                        <el-row>
                            <el-col>
                                <el-card class="box-card">
                                    <div slot="header" class="clearfix">
                                        <span>Season Stats</span>
                                    </div>
                                    <el-table :data="[stats]" style="width: 100%">
                                        <el-table-column
                                            label="Position">
                                        </el-table-column>
                                        <el-table-column v-for="(stat, index) in stats"
                                            :key="index"
                                            :prop="index"
                                            :label="index"
                                            width="50px">
                                        </el-table-column>
                                    </el-table>
                                    <hr />
                                    <PieChart :chartData="games_chart"/>
                                    <hr />
                                    <BarChart :chartData="games_chart"/>
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
                        <dt><div class="list-inner">Player not found!</div></dt>
                    </dl>
                </li>
            </ul>
        </div>
    </div>
</template>
<script>

    export default {
        components: {  },
        data: function() { 
            return {
                player: false,
                last5: false,
                pos: false,
                games: false,
                stats: false,
                games_chart: null,
                countries: null,
                err: false,
                modalVisible: false,
            }
        },
        computed: {
            country: function () {
                return this.countries.filter(obj => {
                    return obj.png === this.player.country_iso.toLowerCase()
                })[0]
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
            this.countries = window.countries
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
                            this.player = res.data.msg.player
                            this.last5 = res.data.msg.last5
                            this.pos = res.data.msg.pos
                            this.games = res.data.msg.games
                            this.stats = res.data.msg.stats
                            this.games_chart = res.data.msg.bar_stats
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
            tipHover: function(evt){
                $(evt.target).tooltip('show')
            },
            tipLeave: function(evt){
                $(evt.target).tooltip('hide')
            },
            infiniteHandler: function($state){
                axios.get('/pthranking/player/games/get?l=' + this.games.length + '&p=' + this.player.player_id).then(response => {
                    if(typeof response.data === 'object' && response.data.length){
                        for (let i=0; i<response.data.length;i++) {
                            let game = response.data[i]
                            this.games.push(game)
                        }
                        $state.loaded();
                    } else {
                        $state.complete();
                    }
                });
            },
            isNumeric: function(str) {
                if (typeof str != "string") return false
                return !isNaN(str) && !isNaN(parseFloat(str))
            },
        }
    }
</script>
<style lang="scss">
.player{
    .fix{
        .el-card__body{
            min-height: 280px;
            max-height: 280px;
            height: 280px;
            overflow-y: scroll;
            margin: 1em 0;
            padding: 0 1em;
        }
    }
    ul{
        margin: 0!important;
        .row-item{
            margin: 0.4em!important;
        }
        &.forums{
            margin: 0!important;
            padding: 0;
            .el-card{
                background-color: inherit;
                color: inherit;
            }
        }
    }
    .avatar {
        .el-card{
            width: auto;
            text-align: center;
            img{
                max-width: 175px;
                width: 100%;
            }
        }
    }
    span{
        &.icons{
            line-height: 1.11em;
            span{
                &.gender{
                    background: transparent;
                    font-size: 100%;
                    i{
                        font-size: 0.7em;
                        &.fa-female{
                            color: var(--pink);
                        }
                        &.fa-male{
                            color: var(--cyan);
                        }
                    }
                }
                &.flag{
                    img{
                        width: 40px;
                        height: 20px;
                    }
                }
            }
        }
    }
    .games{
        .list{
            .game{
                .date{
                    font-size: 0.7em;
                }
                .el-col{
                    padding-bottom: 0;
                    hr{
                        margin-bottom: 0px;
                    }
                }
            }
        }
    }
    .data{
        .el-col{
            padding-bottom: 1em;
        }
    }
    .el-table{
        th {
            background-color: transparent!important;
        }
        tr{
            cursor: default;
            background-color: transparent!important;
            &:hover{
                background-color: transparent!important;
            }
            td{
                background-color: transparent!important;
                &:hover{
                    background-color: transparent!important;
                }
            }
        }
        thead tr{
            background-color: transparent!important;
            &:hover{
                background-color: transparent!important;
            }
            td{
                background-color:transparent!important;
                &:hover{
                    background-color: transparent!important;
                }
            }
        }
    }
    .el-collapse{
        background: transparent !important;
        border-bottom: 0!important;
        border-top: 0!important;
        border-color: transparent!important;
        border-width: 0!important;
        *:not(.sort-caret){
            background: transparent !important;
            border-bottom: 0!important;
            border-top: 0!important;
            border-color: transparent!important;
            border-width: 0!important;
        }
        .el-table{
            .sort-caret{
                border-width: 5px!important;
            }
            .ascending .ascending{
                border-bottom-color: #383c44;
            }
            .descending .descending{
                border-top-color: #383c44;
            }
            .el-table__header-wrapper{
                cursor: pointer!important;
                border-bottom-width: 1px!important;
                border-bottom-color: #EBEEF5!important;
                border-bottom: 1px solid #EBEEF5!important;
            }
            td, th{
                padding: 0.1em 0;
            }
            tr{
                &:last-child{
                    td, th{
                        padding: 0 0 0.2em 0;
                    }
                }
            }
        }
        .el-collapse-item{
            .el-collapse-item__header{
                color: inherit;
                font-size: 1em;
                cursor: pointer!important;
            }
            .el-collapse-item__content {
                padding-bottom: 0.6em;
                color: inherit;
            }
            .el-collapse-item__wrap {
                border-bottom: 0!important;
            }
        }
    }

    .infinite-loading-container{
        visibility: hidden;
        height: 0px;
    }
    @media only screen and (max-width: 991px){
        .after{
            display: none;
        }
    }
    @media only screen and (min-width: 992px){
        .el-col-xs-24 {
            &.avatar{
                padding-left: 1em!important;
            }
        }
        .before{
            display: none;
        }
    }

}
.fd_dark .player{
    background: #242a36 !important;
    ul{
        &.forums{
            .el-card{
                border: 1px solid #383c44;
                background-color: inherit;
                color: inherit;
            }
            .el-card__header {
                border-bottom: 1px solid #383c44;
            }
        }
    }
    li.row:hover{
        background: inherit!important;
    }
    .el-table{
        background-color: transparent!important;
        tr{
            background-color: transparent!important;
            &:hover{
                background-color: transparent!important;
            }
            td{
                background-color:transparent!important;
                &:hover{
                    background-color: transparent!important;
                }
            }
        }
        thead tr{
            background-color: transparent!important;
            &:hover{
                background-color: transparent!important;
            }
            td{
                background-color:transparent!important;
                &:hover{
                    background-color: transparent!important;
                }
            }
        }
    }
}
</style>
