<template>
    <div class="card">
        <div class="card-body">
            <data-tables v-if="game" 
                :data="game" 
                layout="tool, table"
                @row-click="handleRowClick">
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
        data: function() { 
            return {
                game: false,
                titles: [
                    { prop: 'player', label: 'Player'},
                    { prop: 'average', label: 'Average Score'},
                    { prop: 'score', label: 'Final Score'},
                    { prop: 'games', label: 'Season Games'}
                ],
            }
        },
        mounted() {
            this.getTable()
        },
        methods: {
            getTable: function(event){
                let data = {}
                for(let i=1;i<=10;i++){
                    data['u' + i] = this.getUrlParameter('u' + i)
                }
                axios.post('/pthranking/gametable/show', data)
                    .then(res => {
                        if(res.data.status){
                            let g = res.data.msg
                            this.game = []
                            for(let i=0;i<g.length;i++){
                                let p = g[i]
                                let player = {}
                                player.player = p.username
                                player.score = p.final_score/100
                                player.average = p.average_score / 100
                                player.games = p.season_games
                                this.game.push(player)
                            }
                        }else{
                            console.log(res.data.msg)
                            this.game = false
                        }
                    }).catch(err => {
                        console.log(err)
                        this.game = false
                })
            },
            getUrlParameter: function(name) {
                name = name.replace(/[\[]/, '\\[').replace(/[\]]/, '\\]');
                var regex = new RegExp('[\\?&]' + name + '=([^&#]*)');
                var results = regex.exec(location.search);
                return results === null ? '' : decodeURIComponent(results[1].replace(/\+/g, ' '));
            },
            handleCurrentPageChange(page) {},
            handleCurrentChange(currentRow) {},
            handlePrevClick(page) {},
            handleSizeChange(size) {},
            handleSelectionChange(val) {},
            handleRowClick(row){
                let sid = window.location.search.substr(1).substr(4)
                window.location.href = window.location.origin + '/player?u=' + row.player + '&sid=' + sid
            },
        }
    }
</script>
<style>

</style>