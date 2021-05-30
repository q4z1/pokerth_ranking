<template>
    <div class="container-fluid player">
        <div class="row justify-content-center">
            <div class="col-md-12">
                <div v-if="player" class="card">
                    <div class="card-header">
                        <div class="row">
                            <div class="col">
                                <h1><div class="name">{{ player.username }}</div>
                                    <div class="icons">
                                        <span class="gender" v-if="player.gender">
                                            <i v-if="player.gender === 'f'" class="icon fa-female gender" />
                                            <i v-else-if="player.gender === 'm'" class="icon fa-male gender" />
                                        </span>
                                        <span v-if="player.country_iso && country.svg != 'n/a'" class="flag">
                                            <img data-toggle="tooltip" @mouseenter="tipHover" @mouseleave="tipLeave" :title="country.title" :src="'/images/flags/' + country.svg + '.svg'" />
                                        </span>
                                    </div>
                                </h1>
                            </div>
                            <div v-if="avatar" class="col avatar">
                                <img :src="avatar" class="float-right" />
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h3>Season Data</h3>
                                    </div>
                                    <div class="card-body">
                                        <ul class="list-group data">
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                Ranking position:
                                                <span class="badge badge-default">{{ pos }}</span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                Final Score:
                                                <span class="badge badge-default">{{ (player.ranking.final_score/100) }}</span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                Ø Points:
                                                <span class="badge badge-default">{{ (player.ranking.average_score/100) }}</span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                Total Points:
                                                <span class="badge badge-default">{{ player.ranking.points_sum }}</span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                Number of games:
                                                <span class="badge badge-default">{{ player.ranking.season_games }}</span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                Last 5 games place:
                                                <span class="badge badge-default">{{ last5.join(', ') }}</span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                Location:
                                                <span class="badge badge-default"></span>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h3>Season Games</h3>
                                    </div>
                                    <div class="card-body" v-if="games">
                                        <ul class="list-group games">
                                            <li v-for="g in games" :key="g.game_idgame" class="game list-group-item ">
                                                <div class="row">
                                                    <div class="col text-primary" :data-gid="g.game_idgame" @click="getGame">{{ g.game.name }}</div>
                                                </div>
                                                <div class="row">
                                                    <div class="col">{{ g.game.start_time }} - {{ g.game.end_time }}</div>
                                                </div>
                                            </li>
                                        </ul>
                                        <infinite-loading @infinite="infiniteHandler"></infinite-loading>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <hr />
                        <div class="row">
                            <div class="col">
                                <div class="card">
                                    <div class="card-header">
                                        <h3>Season Stats</h3>
                                    </div>
                                    <div class="card-body" v-if="stats">
                                        <table class="table table-responsive">
                                            <thead>
                                                <tr>
                                                    <th>Position:</th>
                                                    <th>1.</th>
                                                    <th>2.</th>
                                                    <th>3.</th>
                                                    <th>4.</th>
                                                    <th>5.</th>
                                                    <th>6.</th>
                                                    <th>7.</th>
                                                    <th>8.</th>
                                                    <th>9.</th>
                                                    <th>10.</th>
                                                    <th style="border-left: 1px solid #bbbbbb">Total Games:</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td></td>
                                                    <td>{{ stats['1'] }}</td>
                                                    <td>{{ stats['2'] }}</td>
                                                    <td>{{ stats['3'] }}</td>
                                                    <td>{{ stats['4'] }}</td>
                                                    <td>{{ stats['5'] }}</td>
                                                    <td>{{ stats['6'] }}</td>
                                                    <td>{{ stats['7'] }}</td>
                                                    <td>{{ stats['8'] }}</td>
                                                    <td>{{ stats['9'] }}</td>
                                                    <td>{{ stats['10'] }}</td>
                                                    <td style="border-left: 1px solid #bbbbbb">{{ player.ranking.season_games }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                        <hr />
                                        <PieChart :chartData="games_chart"/>
                                        <hr />
                                        <BarChart :chartData="games_chart"/>
                                        <hr />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card p-4 text-center" v-if="err">
                    <strong class="text-danger">{{ err }}</strong>
                </div>
            </div>
        </div>
        <game-component @close="closeGame()" v-if="game" :game="game"></game-component>
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
                game: false,
                countries: null,
                err: false,
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
            getGame: function(event){
                let gid = $(event.target).attr('data-gid')
                axios.get('/pthranking/game/get?g=' + gid)
                    .then(res => {
                        if(res.data.status){
                            this.game = res.data.msg
                        }else{
                            this.game = false
                        }
                    }).catch(err => {
                        console.log(err)
                        this.game = false
                })
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
            closeGame: function() {
                this.game = false
            }
        }
    }
</script>
<style lang="scss">
    .content ul, .content ol {
        margin: 0.8em 0;
    }
    .card-header{
        .avatar {
            img{
                max-width: 175px;
            }
            
        }
        h1{
            div{
                float: left;
                &.name{
                    margin-right: 0.5em;
                }
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
                                margin-top: 0.1em;
                                width: 40px;
                                height: 20px;
                            }
                        }
                    }
                }
            }
        }
    }
    li.game{
        font-size: smaller!important;
    }
    li.game div.text-primary{
        cursor: pointer;
    }
    .player{
        position: relative;
    }
</style>
<style scoped>
    ul.games{
        min-height: 336px;
        max-height: 336px;
        overflow-y: auto;
    }
    .ul.data{
        min-height: 336px;
        max-height: 336px;
    }
    .infinite-loading-container{
        visibility: hidden;
        height: 0px;
    }
    .card{
        background-color: transparent;
    }
</style>