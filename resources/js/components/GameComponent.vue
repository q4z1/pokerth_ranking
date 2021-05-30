<template>
    <div class="game card">
        <div class="card-header">
            <div class="row">
                <div class="col-md-6">
                    <h1>{{ gameTitle }}</h1>
                    <span>{{ game[0].start_time }} - {{ game[0].end_time }}</span>
                </div>
                <div class="col-md-6 text-right">
                    <el-button type="default" @click="closeGame">Close</el-button></div>
            </div>
        </div>
        <div class="card-body" v-if="theGame">
            <data-tables :data="theGame" layout="tool, table">
                <el-table-column v-for="title in titles"
                :prop="title.prop"
                :label="title.label"
                :key="title.label"
                sortable="custom">
                </el-table-column>
            </data-tables>
        </div>
    </div>
</template>
<script>
    export default {
        props: ['game'],
        data: function() { 
            return {
                theGame: false,
                titles: [
                    { prop: 'place', label: 'Place'},
                    { prop: 'player', label: 'Player'},
                    { prop: 'average', label: 'Average Score'},
                    { prop: 'score', label: 'Final Score'},
                    { prop: 'games', label: 'Season Games'}
                ],
                gameTitle: '',
            }
        },
        mounted() {
            this.theGame = []
            this.gameTitle = this.game[0].name
            for(let i=0;i<this.game[0].players.length;i++){
                let p = this.game[0].players[i]
                let player = {}
                player.player = p.player.username
                player.place = p.place
                player.score = p.player.ranking.final_score/100
                player.average = p.player.ranking.average_score / 100
                player.games = p.player.ranking.season_games
                this.theGame.push(player)
            }
        },
        methods: {
            closeGame: function(){
                this.$emit("close");
            }
        }
    }
</script>
<style lang="scss">
    .game.card{
        position: absolute;
        top: 0.5em;
        left: 1em;
        right: 1em;
        background: #dddddd!important;
        box-shadow: 0 0 0.1em 0.1em gray;
        .el-table tr td, .el-table tr th {
            cursor: default;
        }
    }
</style>