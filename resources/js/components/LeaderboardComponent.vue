<template>
    <div class="leaderboard">
        <div class="inner">
            <ul class="topiclist">
                <li class="header">
                    <dl class="row-item">
                        <dt><div class="list-inner">
                            Leaderboard
                        </div></dt>
                    </dl>
                </li>
            </ul>
            <ul class="topiclist forums">
		        <li class="row">
                    <div class="list-inner">
                        <el-row>
                            <el-col :span="6">
                                <el-input label="Search:" size="small" v-model="filters.value" placeholder="Username"></el-input>
                            </el-col>
                        </el-row>
                        <el-row>
                            <el-col>
                                <data-tables-server 
                                    :data="data" 
                                    :total="total" 
                                    :loading="loading" 
                                    :table-props="tableProps"
                                    :filters="filters"
                                    :page-size="25"
                                    @query-change="loadData"
                                    @current-page-change="handleCurrentPageChange"
                                    @current-change="handleCurrentChange"
                                    @prev-click="handlePrevClick"
                                    @size-change="handleSizeChange"
                                    @selection-change="handleSelectionChange"
                                    @row-click="handleRowClick"
                                    :pagination-props="{ pageSizes: [10, 25, 50] }">
                                    <el-table-column prop="rank_pos" label="#" sortable="custom">
                                        <template #default="{ row }">
                                            {{ row.rank_pos }}
                                        </template>
                                    </el-table-column>
                                    <el-table-column prop="username" label="Player" sortable="custom">
                                        <template #default="{ row }">
                                            {{ row.username }}
                                        </template>
                                    </el-table-column>
                                    <el-table-column prop="gender_country" label="Gender/Country">
                                        <template #default="{ row }">
                                            <div class="icons">
                                                <el-tooltip v-if="row.gender_country.gender" effect="dark" placement="top-start" :content="(row.gender_country.gender === 'f') ? 'female' : 'male'">
                                                    <span class="gender">
                                                        <i v-if="row.gender_country.gender === 'f'" class="icon fa-female gender" />
                                                        <i v-else-if="row.gender_country.gender === 'm'" class="icon fa-male gender" />
                                                    </span>
                                                </el-tooltip>
                                                <el-tooltip v-if="row.gender_country.country && country(row.gender_country.country).svg != 'n/a'" effect="dark" placement="top-start" :content="country(row.gender_country.country).title">
                                                    <span class="flag">
                                                        <img :alt="country(row.gender_country.country).title" :title="country(row.gender_country.country).title" :src="'/images/flags/' + country(row.gender_country.country).svg + '.svg'" />
                                                    </span>
                                                </el-tooltip>
                                            </div>
                                        </template>
                                    </el-table-column>
                                    <el-table-column prop="average_score" label="Ø Points" sortable="custom">
                                        <template #default="{ row }">
                                            {{ row.average_score }}
                                        </template>
                                    </el-table-column>
                                    <el-table-column prop="season_games" label="Games" sortable="custom">
                                        <template #default="{ row }">
                                            {{ row.season_games }}
                                        </template>
                                    </el-table-column>
                                    <el-table-column prop="final_score" label="Final Score" sortable="custom">
                                        <template #default="{ row }">
                                            {{ row.final_score }}
                                        </template>
                                    </el-table-column>
                                    <el-table-column prop="points_sum" label="Total Points" sortable="custom">
                                        <template #default="{ row }">
                                            {{ row.points_sum }}
                                        </template>
                                    </el-table-column>
                                </data-tables-server>
                                <hr />
                                <h3>Ranking calculation:</h3>
                                <ol class="formular">
                                    <li><b>Placement Points:</b><br />
                                    1. = 15 | 2. = 9 | 3. = 6 | 4. = 4 | 5. = 3 | 6. = 2 | 7. = 1
                                    </li>
                                    <li><b>Formula:</b><br />
                                    25 * average * (1 - 10000 / (10000 + games^3))
                                    </li>
                                </ol>
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
        components: {

        },
        data: function() { 
            return {
                data: null,
                countrie: null,
                filters: {
                    props: 'username',
                    value: '',
                    def: [{
                        'value': '',
                    }]
                },
                customButtonsForRow(row) {
                    return [
                    ]
                },
                loading: false,
                total: 0,
                tableProps: {
                    defaultSort: {
                        prop: 'rank_pos',
                        order: 'descending'
                    }
                },
                layout: 'table, pagination',
                queryInfo: false,
            }
        },
        computed: {

        },
        mounted() {
            this.countries = window.countries
        },
        methods: {
            async loadData(queryInfo) {
                this.loading = true
                const res = await axios.post('/pthranking/ranking/leaderboard', queryInfo);
                let { data, total } = {data: res.data.data, total: res.data.total}
                this.data = data
                this.total = total
                this.loading = false
                this.queryInfo = queryInfo
            },
            handleCurrentPageChange(page) {},
            handleCurrentChange(currentRow) {},
            handlePrevClick(page) {},
            handleSizeChange(size) {},
            handleSelectionChange(val) {},
            handleRowClick(row){
                let sid = window.location.search.substr(1).substr(4)
                window.open(window.location.origin + '/player?p=' + row.player_id, '_blank')
            },
            country: function (country_iso) {
                return this.countries.filter(obj => {
                    return obj.png === country_iso.toLowerCase()
                })[0]
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
.leaderboard{
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
                cursor: pointer;
                &:hover{
                    background-color: #c7cad0!important;
                }
                td{
                    background-color: transparent!important;
                    &:hover{
                        background-color: transparent!important;
                    }
                    .icons{
                        line-height: 1.11em;
                        span{
                            &.gender{
                                background: transparent;
                                font-size: 100%;
                                i{
                                    font-size: 1.4em;
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
                                    margin-top: 0;
                                    margin-bottom: 0.3em;
                                    // width: 35px;
                                    height: 18px;
                                }
                            }
                        }
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
.fd_dark .leaderboard{
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
