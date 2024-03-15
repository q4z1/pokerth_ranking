<template>
    <div class="downloads">
        <div class="inner">
            <ul class="topiclist">
                <li class="header">
                    <dl class="row-item">
                        <dt><div class="list-inner">
                            Card Decks & Table Styles
                        </div></dt>
                    </dl>
                </li>
            </ul>
            <ul class="topiclist forums">
		        <li class="row">
                    <div class="list-inner">
                        <el-row>
                            <el-col :span="12" v-if="cards">
                                <el-table
                                    :data="cards"
                                    :show-header="false"
                                    style="width: 100%">
                                    <el-table-column>
                                    <template slot-scope="scope">
                                        <el-row style="display: flex; align-items: center">
                                            <el-col :span="8"><img width="160" v-if="scope.row.preview" :src="scope.row.preview"></el-col>
                                            <el-col :span="16" style="margin-left: 0.4em;"><a :href="'/download/styles/cards/' + scope.row.filename" :title="scope.row.filename">{{ scope.row.filename }}</a></el-col>
                                        </el-row>
                                    </template>
                                    </el-table-column>
                                </el-table>
                            </el-col>
                            <el-col :span="12" v-if="tables">
                                <el-table
                                    :data="tables"
                                    :show-header="false"
                                    style="width: 100%">
                                    <el-table-column>
                                    <template slot-scope="scope">
                                        <el-row style="display: flex; align-items: center">
                                            <el-col :span="8"><img width="160" v-if="scope.row.preview" :src="scope.row.preview"></el-col>
                                            <el-col :span="16" style="margin-left: 0.4em;"><a :href="'/download/styles/table/' + scope.row.filename" :title="scope.row.filename">{{ scope.row.filename }}</a></el-col>
                                        </el-row>
                                    </template>
                                    </el-table-column>
                                </el-table>
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
                cards: null,
                tables: null,
            }
        },
        mounted() {
            this.getStyles()
        },
        methods: {
            getStyles: function(){
                axios.get('/pthranking/styles')
                .then(res => {
                    if(res.data.status){
                        this.cards = res.data.cards
                        this.tables = res.data.tables
                    }
                }).catch(err => {
                    console.log(err)
                })
            },
        }
    }
</script>
<style lang="scss">
.styles{
    .forums li{
        margin-bottom: 1em !important;
    }
    .el-table{
        .cell{
            padding-left: 0 !important;
        }
        th {
            background-color: transparent!important;
        }
        tr{
            background-color: transparent!important;
            &:hover{
                background-color: transparent !important;
            }
            td{
                background-color: transparent!important;
                &:hover{
                    background-color: transparent!important;
                }
            }
        }
        td, th{
            border-bottom: none;
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
        &::after, &::before {
            background-color: transparent !important;
        }
    }
    p {
        margin-top: 1em;
        text-align: center;
    }
}
.fd_dark .downloads{
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