<template>
    <div class="game">
        <el-table v-if="theGame" :data="theGame"
            :default-sort = "{prop: 'place', order: 'ascending'}"
            style="width: 100%">
            <el-table-column v-for="title in titles"
            :prop="title.prop"
            :label="title.label"
            :key="title.label"
            sortable>
            </el-table-column>
        </el-table>
    </div>
</template>
<script>
    export default {
        props: ['gameid'],
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
            }
        },
        mounted() {
            this.getGame()
        },
        methods: {
            getGame: function(){
                axios.get('/pthranking/game/get?g=' + this.gameid)
                    .then(res => {
                        if(res.data.status){
                            let game = res.data.msg[0]
                            this.theGame = []
                            for(let i in game.players){
                                let p = game.players[i]
                                let player = {}
                                if(typeof p !== 'undefined' && p.player !== null){
                                    player.player = p.player.username
                                    player.place = p.place
                                    player.score = p.player.ranking.final_score/100
                                    player.average = p.player.ranking.average_score / 100
                                    player.games = p.player.ranking.season_games
                                }else{
                                    player.player = 'n/a'
                                    player.place = p.place
                                    player.score = 0
                                    player.average = 0
                                    player.games = 0
                                }

                                this.theGame.push(player)
                            }
                        }else{
                            this.theGame = false
                        }
                    }).catch(err => {
                        console.log(err)
                        this.theGame = false
                })
            },
        }
    }
</script>
<style lang="scss">

</style>