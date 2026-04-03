<template>
    <div class="leaderboard">
        <div class="inner">
            <ul class="topiclist">
                <li class="header">
                    <dl class="row-item">
                        <dt><div class="list-inner">Leaderboard</div></dt>
                    </dl>
                </li>
            </ul>
            <ul class="topiclist forums">
                <li class="row">
                    <div class="list-inner">
                        <el-row style="margin-bottom:1em;" :gutter="16">
                            <el-col :span="12">
                                Search:
                                <el-input
                                    class="username"
                                    v-model="searchQuery"
                                    placeholder="Username"
                                    @input="onSearchInput"
                                />
                            </el-col>
                            <el-col :span="12">
                                <div class="season">Season:</div>
                                <el-select @change="handleSelectSeason" v-model="season" placeholder="Select a Season" style="width:100%">
                                    <el-option
                                        v-for="s in seasons"
                                        :key="s"
                                        :label="s"
                                        :value="s"
                                    />
                                </el-select>
                            </el-col>
                        </el-row>
                        <el-row>
                            <el-col class="sc-table">
                                <el-table
                                    v-loading="loading"
                                    :data="data"
                                    :default-sort="{ prop: 'rank_pos', order: 'descending' }"
                                    style="width:100%"
                                    @sort-change="handleSortChange"
                                    @row-click="handleRowClick"
                                >
                                    <el-table-column prop="rank_pos" label="#" sortable="custom" width="60" />
                                    <el-table-column prop="username" label="Player" sortable="custom" />
                                    <el-table-column prop="gender_country" label="Gender/Country">
                                        <template #default="{ row }">
                                            <div class="icons">
                                                <el-tooltip
                                                    v-if="row.gender_country.gender"
                                                    effect="dark"
                                                    placement="top-start"
                                                    :content="row.gender_country.gender === 'f' ? 'female' : 'male'"
                                                >
                                                    <span class="gender">
                                                        <i v-if="row.gender_country.gender === 'f'" class="icon fa-female" />
                                                        <i v-else-if="row.gender_country.gender === 'm'" class="icon fa-male" />
                                                    </span>
                                                </el-tooltip>
                                                <el-tooltip
                                                    v-if="row.gender_country.country && country(row.gender_country.country) && country(row.gender_country.country).svg !== 'n/a'"
                                                    effect="dark"
                                                    placement="top-start"
                                                    :content="country(row.gender_country.country).title"
                                                >
                                                    <span class="flag">
                                                        <img
                                                            :alt="country(row.gender_country.country).title"
                                                            :src="'/images/flags/' + country(row.gender_country.country).svg + '.svg'"
                                                        />
                                                    </span>
                                                </el-tooltip>
                                            </div>
                                        </template>
                                    </el-table-column>
                                    <el-table-column prop="average_score" label="Ø Points" sortable="custom" />
                                    <el-table-column prop="season_games" label="Games" sortable="custom" />
                                    <el-table-column prop="final_score" label="Final Score" sortable="custom" />
                                    <el-table-column prop="points_sum" label="Total Points" sortable="custom" />
                                </el-table>
                                <el-pagination
                                    class="pagination-bar"
                                    style="margin-top:1em;"
                                    background
                                    layout="sizes, prev, pager, next"
                                    :total="total"
                                    :page-size="pageSize"
                                    :current-page="currentPage"
                                    :page-sizes="[25, 50, 100]"
                                    @current-change="handlePageChange"
                                    @size-change="handleSizeChangeEvt"
                                />
                            </el-col>
                        </el-row>
                        <hr />
                        <h3>Ranking calculation:</h3>
                        <ol class="formular">
                            <li><b>Placement Points:</b><br />1. = 15 | 2. = 9 | 3. = 6 | 4. = 4 | 5. = 3 | 6. = 2 | 7. = 1</li>
                            <li><b>Formula:</b><br />25 * average * (1 - 10000 / (10000 + games^3))</li>
                        </ol>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>
<script>
    export default {
        data() {
            return {
                data: [],
                searchQuery: '',
                searchTimer: null,
                season: 'current',
                seasons: [],
                loading: false,
                total: 0,
                currentPage: 1,
                pageSize: 50,
                sortProp: 'rank_pos',
                sortOrder: 'descending',
                countries: [],
            }
        },
        created() {
            const params = new URLSearchParams(window.location.search)
            const seasonParam = params.get('season')
            if (seasonParam) this.season = seasonParam
        },
        mounted() {
            this.countries = window.countries || []
            this.loadData()
        },
        methods: {
            async loadData() {
                this.loading = true
                try {
                    const queryInfo = {
                        page: this.currentPage,
                        pageSize: this.pageSize,
                        sort: { prop: this.sortProp, order: this.sortOrder },
                        filters: this.searchQuery ? { value: this.searchQuery, props: 'username' } : null,
                    }
                    const res = await axios.post('/pthranking/ranking/leaderboard/' + this.season, queryInfo)
                    this.data = res.data.data
                    this.total = res.data.total
                    const seasons = res.data.seasons || []
                    if (!seasons.includes('current')) seasons.unshift('current')
                    this.seasons = seasons
                } finally {
                    this.loading = false
                }
            },
            onSearchInput() {
                clearTimeout(this.searchTimer)
                this.searchTimer = setTimeout(() => {
                    this.currentPage = 1
                    this.loadData()
                }, 350)
            },
            handleSelectSeason() {
                this.currentPage = 1
                history.pushState(null, '', '/app.php/leaderboard?season=' + this.season)
                this.loadData()
            },
            handleSortChange({ prop, order }) {
                this.sortProp = prop
                this.sortOrder = order
                this.currentPage = 1
                this.loadData()
            },
            handlePageChange(page) {
                this.currentPage = page
                this.loadData()
            },
            handleSizeChangeEvt(size) {
                this.pageSize = size
                this.currentPage = 1
                this.loadData()
            },
            handleRowClick(row) {
                window.open(window.location.origin + '/player?p=' + row.player_id, '_blank')
            },
            country(country_iso) {
                if (!country_iso || !this.countries) return null
                return this.countries.find(obj => obj.png === country_iso.toLowerCase()) || null
            },
        },
    }
</script>
<style>
/* Leaderboard-specific styles – global styles in resources/css/pth.css */
.leaderboard .sc-table { cursor: pointer; }
.leaderboard .sc-table .el-table th,
.leaderboard .sc-table .el-table tr,
.leaderboard .sc-table .el-table td { background-color: transparent !important; }
.leaderboard .sc-table .el-table tr { cursor: pointer; }
.leaderboard .sc-table .el-table .icons { display: flex; align-items: center; gap: 4px; }
.leaderboard .sc-table .el-table .icons span.flag img { height: 18px; vertical-align: middle; }
.leaderboard .sc-table .el-table .icons span.gender i { color: inherit; }
</style>
