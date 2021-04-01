<template>
    <div class="container-fluid leaderboard">
        <div class="row justify-content-center">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <div class="row">
                            <div class="col-md-4">
                                <label class="sr-only" for="username">Username</label>
                                <div class="input-group mb-2">
                                    <div class="input-group-prepend">
                                        <div class="input-group-text"><i class="fa fa-search"></i></div>
                                    </div>
                                    <input type="text" class="form-control" id="username" placeholder="Username"
                                        @keyup="autocompleteUsername"
                                    >
                                    <div id="suggestions"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <vuetable ref="vuetable" v-if="leaderboard"
                            :fields="tableFields"
                            :isApiMode="false"
                            :data="leaderboard"
                            @vuetable:pagination-data="onPaginationData"
                        ></vuetable>
                        <!-- <vuetable-pagination ref="pagination"  v-if="leaderboard"
                            @vuetable-pagination:change-page="onChangePage"
                        ></vuetable-pagination> -->
                    </div>
                </div>
                <hr />
                <div class="col-12">
                    <h3>Ranking calculation:</h3>
                    <ol class="formular">
                        <li><b>Placement Points:</b><br />
                        1. = 15 | 2. = 9 | 3. = 6 | 4. = 4 | 5. = 3 | 6. = 2 | 7. = 1
                        </li>
                        <li><b>Formula:</b><br />
                        25 * average * (1 - 10000 / (10000 + games^3))
                        </li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</template>
<script>
    import { Vuetable, VuetablePagination } from 'vuetable-2'
    import VueAutosuggest from "vue-autosuggest"
    export default {
        components: {
            Vuetable,
            VuetablePagination,
            VueAutosuggest
        },
        data: function() { 
            return {
                leaderboard: false,
                tableFields: [
                    {
                        name: 'player_id',
                        // sortField: 'rank'
                    },
                    {
                        name: 'username',
                        sortField: 'username'
                    },
                    {
                        name: 'average_score',
                        // sortField: 'average_points',
                        title: 'Ø Points'
                    },
                    {
                        name: 'season_games',
                        // sortField: 'season_games',
                        title: 'Games'
                    },
                    {
                        name: 'final_score',
                        // sortField: 'final_score',
                        title: 'Final Score'
                    },
                    {
                        name: 'points_sum',
                        // sortField: 'final_score',
                        title: 'Total points'
                    },
                    {
                        name: 'gender',
                    },
                    {
                        name: 'country_iso',
                        title: 'Country'
                    },
                ]
            }
        },
        mounted() {
            this.getLeaderboard()
        },
        methods: {
            getLeaderboard: function(event){
                axios.get(location.protocol + '//' + location.hostname + '/pthranking/ranking/leaderboard')
                    .then(res => {
                        this.leaderboard = res.data
                        // if(res.data.status){
                        //     this.leaderboard = res.data.msg
                        // }else{
                        //     console.log(res.data.msg)
                        //     this.leaderboard = false
                        // }
                    }).catch(err => {
                        console.log(err)
                        this.leaderboard = false
                })
            },
            autocompleteUsername: function(event){
                let el = document.getElementById('username')
                let val = el.value
                if(val.length < 2) return
                console.log(val)
                // @TODO: needs more players so that there is pagination
            },
            onPaginationData (paginationData) {
                this.$refs.pagination.setPaginationData(paginationData)
            },
            onChangePage (page) {
                this.$refs.vuetable.changePage(page)
            }
        }
    }
</script>
<style scoped>
    .card{
        background-color: transparent;
    }
    .row .pagination{
        display: flex;
    }
    .page-link {
        height: calc(1.4em + 0.75rem + 2px);
        position: relative;
        display: block;
        padding: .5rem 1.5rem;
        margin-left: -1px;
        line-height: calc(1.4em + 0.75rem + 2px);
        vertical-align: middle;
    }
    .formular{
        margin-left: 0.9em;
    }
</style>