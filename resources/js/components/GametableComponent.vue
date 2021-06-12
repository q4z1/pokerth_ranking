<template>
    <div class="gametable">
        <div class="inner">
            <ul class="topiclist">
                <li class="header">
                    <dl class="row-item">
                        <dt><div class="list-inner">
                            Gametable
                        </div></dt>
                    </dl>
                </li>
            </ul>
            <ul class="topiclist forums">
		        <li class="row">
                    <div class="list-inner">
                        <el-row>
                            <el-col>
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
                            </el-col>
                        </el-row>
                    </div>
		        </li>
            </ul>
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
                    { prop: 'rank', label: '#'},
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
                                player.rank = p.rank_pos
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
                window.open(window.location.origin + '/player?u=' + row.player, '_blank')
            },
        }
    }
</script>
<style lang="scss">
.el-select-dropdown{
    .el-select-dropdown__item.selected {
        color: inherit!important;
    }
}
.gametable{
    ul{
        margin: 0!important;
        .row-item{
            margin: 0.4em!important;
        }
    }
    input{
        background-color: transparent!important;
        color: inherit;
    }
    .el-row{
        padding-bottom: 1em;
    }
    .sc-table{
        .el-table{
            th {
            background-color: transparent!important;
            }
            tr{
                background-color: transparent!important;
                &:hover{
                    background-color: #c7cad0!important;
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
        .pagination-bar{
            margin-top: 1em;
            ul > li{
                float: left;
                background: transparent!important;
                &.active{
                    color: #606266;
                }
            }
            button{
                background-color: transparent!important;
                color: #2d3039!important;
            }
        }
    }
    .el-select .el-input.is-focus .el-input__inner,
    .el-pagination__sizes .el-input .el-input__inner:hover,
    .el-input.is-active .el-input__inner, .el-input__inner:focus,
    .el-input.is-active .el-input__inner, .el-input__inner:focus {
        border-color: #606266;
    }
    .el-table .descending .sort-caret.descending{
        border-top-color: #606266;
    }
    .el-table .ascending .sort-caret.ascending{
        border-bottom-color: #606266;
    }
}
.fd_dark .gametable{
    background: #242a36 !important;
    .sc-table{
        .el-table{
            background-color: transparent!important;
            tr{
                background-color: transparent!important;
                &:hover{
                    background-color: #2d3039!important;
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
        .pagination-bar{
            button{
                background-color: transparent!important;
                color: #c7cad0!important;
            }
            input{
                margin-top: 1px;
            }
        }
    }
}
</style>