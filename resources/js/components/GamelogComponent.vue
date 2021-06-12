<template>
    <div>
        <div v-if="game" class="game-log inner">
            <ul class="topiclist">
                <li class="header">
                    <dl class="row-item">
                        <dt><div class="list-inner">
                            Logfile-Analysis
                        </div></dt>
                    </dl>
                </li>
            </ul>
            <ul class="topiclist forums">
		        <li class="row">
                    <div class="list-inner">
                        <el-row>
                            <el-col :xs="12" :md="6"><strong>Game ID:</strong></el-col>
                            <el-col :xs="12" :md="18">
                                <el-select v-model="game_id" @change="getGame" size="small">
                                <el-option
                                    v-for="item in game_ids"
                                    :key="item.value"
                                    :label="item.label"
                                    :value="item.value">
                                </el-option>
                                </el-select>
                            </el-col>
                        </el-row>
                        <el-row>
                            <el-col>
                                <hr />
                            </el-col>
                        </el-row>
                        <el-row>
                            <el-col :xs="24" :md="12" class="pb">
                                <h3>Basic data</h3>
                                <el-row>
                                    <el-col :span="12"><strong>Winner:</strong></el-col>
                                    <el-col :span="12"><a :href="'/player?u=' + game['player_list'][1][0]" :title="game['player_list'][1][0]">{{ game['player_list'][1][0] }}</a></el-col>
                                </el-row>
                                <el-row>
                                    <el-col :span="12"><strong>Number of Players:</strong></el-col>
                                    <el-col :span="12">{{ game['player_list'][0].length }}</el-col>
                                </el-row>
                                <el-row>
                                    <el-col :span="12"><strong>Hands:</strong></el-col>
                                    <el-col :span="12">{{ game['player_list'][3][0] }}</el-col>
                                </el-row>
                                <el-row>
                                    <el-col :span="12"><strong>Date:</strong></el-col>
                                    <el-col :span="12">{{ game['session']['Date'] }}</el-col>
                                </el-row>
                                <el-row>
                                    <el-col :span="12"><strong>Session started:</strong></el-col>
                                    <el-col :span="12">{{ game['session']['Time'] }}</el-col>
                                </el-row>
                            </el-col>
                            <el-col :xs="24" :md="12" class="pb">
                                <h3>Ranking</h3>
                                <el-table :data="ranking" style="width: 100%" @row-click="rowClick" class="ranking">
                                    <el-table-column
                                        prop="pos"
                                        label="#"
                                        width="50">
                                    </el-table-column>
                                    <el-table-column
                                        prop="player"
                                        label="Player">
                                    </el-table-column>
                                    <el-table-column
                                        prop="hand"
                                        label="Hand">
                                    </el-table-column>
                                    <el-table-column
                                        prop="_"
                                        label=" ">
                                        <template slot-scope="scope">
                                            <span v-html="scope.row['_']"></span>
                                        </template>
                                    </el-table-column>
                                </el-table>
                            </el-col>
                        </el-row>
                        <el-row class="pb">
                            <el-col>
                                <h3>Hand Cash</h3>
                                <line-chart-component :chart-data="datacollection1" :options="options1"></line-chart-component>
                            </el-col>
                        </el-row>
                        <el-row class="pb">
                            <el-col>
                                <h3>Pot Size</h3>
                                <bar-chart-component :chart-data="datacollection2" :options="options2"></bar-chart-component>
                            </el-col>
                        </el-row>
                        <el-row class="pb">
                            <el-col>
                                <h3>Most hands played</h3>
                                <el-table :data="most_hands" style="width: 100%" class="ranking">
                                    <el-table-column
                                        prop="pos"
                                        label="#"
                                        width="50">
                                    </el-table-column>
                                    <el-table-column
                                        prop="player"
                                        label="Player">
                                    </el-table-column>
                                    <el-table-column
                                        prop="count"
                                        label="Count">
                                    </el-table-column>
                                    <el-table-column
                                        prop="_10_to_7_player"
                                        label="10 to 7">
                                    </el-table-column>
                                    <el-table-column
                                        prop="_6_to_4_player"
                                        label="6 to 4">
                                    </el-table-column>
                                    <el-table-column
                                        prop="_3_to_1_player"
                                        label="3 to 1">
                                    </el-table-column>
                                </el-table>
                            </el-col>
                        </el-row>
                        <el-row class="pb">
                            <el-col>
                                <h3>Best hands</h3>
                                <el-table :data="best_hands" style="width: 100%" class="ranking">
                                    <el-table-column
                                        prop="pos"
                                        label="#"
                                        width="50">
                                    </el-table-column>
                                    <el-table-column
                                        prop="cards"
                                        label="Cards">
                                    </el-table-column>
                                    <el-table-column
                                        prop="player"
                                        label="Player">
                                    </el-table-column>
                                    <el-table-column
                                        prop="hand"
                                        label="Hand">
                                    </el-table-column>
                                    <el-table-column
                                        prop="result"
                                        label="Result">
                                    </el-table-column>
                                </el-table>
                            </el-col>
                        </el-row>
                        <el-row class="pb">
                            <el-col>
                                <h3>Most wins</h3>
                                <el-table :data="most_wins" style="width: 100%" class="ranking">
                                    <el-table-column
                                        prop="pos"
                                        label="#"
                                        width="50">
                                    </el-table-column>
                                    <el-table-column
                                        prop="player"
                                        label="Player">
                                    </el-table-column>
                                    <el-table-column
                                        prop="count *"
                                        label="Count *)">
                                    </el-table-column>
                                    <el-table-column
                                        prop="highest"
                                        label="Highest">
                                    </el-table-column>
                                </el-table>
                            </el-col>
                        </el-row>
                        <el-row class="pb">
                            <el-col>
                                <h3>Highest wins</h3>
                                <el-table :data="highest_wins" style="width: 100%" class="ranking">
                                    <el-table-column
                                        prop="pos"
                                        label="#"
                                        width="50">
                                    </el-table-column>
                                    <el-table-column
                                        prop="amount"
                                        label="Amount">
                                    </el-table-column>
                                    <el-table-column
                                        prop="player"
                                        label="Player">
                                    </el-table-column>
                                    <el-table-column
                                        prop="hand"
                                        label="Hand">
                                    </el-table-column>
                                </el-table>
                            </el-col>
                        </el-row>
                        <el-row class="pb">
                            <el-col>
                                <h3>Longest wins</h3>
                                <el-table :data="longest_wins" style="width: 100%" class="ranking">
                                    <el-table-column
                                        prop="pos"
                                        label="#"
                                        width="50">
                                    </el-table-column>
                                    <el-table-column
                                        prop="duration"
                                        label="Duration">
                                    </el-table-column>
                                    <el-table-column
                                        prop="player"
                                        label="Player">
                                    </el-table-column>
                                    <el-table-column
                                        prop="total_gain"
                                        label="Total gain">
                                    </el-table-column>
                                </el-table>
                            </el-col>
                        </el-row>
                        <el-row class="pb">
                            <el-col>
                                <h3>Longest losses</h3>
                                <el-table :data="longest_losses" style="width: 100%" class="ranking">
                                    <el-table-column
                                        prop="pos"
                                        label="#"
                                        width="50">
                                    </el-table-column>
                                    <el-table-column
                                        prop="duration"
                                        label="Duration">
                                    </el-table-column>
                                    <el-table-column
                                        prop="player"
                                        label="Player">
                                    </el-table-column>
                                    <el-table-column
                                        prop="total_loss"
                                        label="Total loss">
                                    </el-table-column>
                                </el-table>
                            </el-col>
                        </el-row>
                        <el-row class="pb">
                            <el-col>
                                <h3>Most bets/raises</h3>
                                <el-table :data="most_bets" style="width: 100%" class="ranking">
                                    <el-table-column
                                        prop="pos"
                                        label="#"
                                        width="50">
                                    </el-table-column>
                                    <el-table-column
                                        prop="player"
                                        label="Player">
                                    </el-table-column>
                                    <el-table-column
                                        prop="Count **"
                                        label="Count **)">
                                    </el-table-column>
                                </el-table>
                            </el-col>
                        </el-row>
                        <el-row class="pb">
                            <el-col>
                                <h3>Most all in</h3>
                                <el-table :data="most_bingo" style="width: 100%" class="ranking">
                                    <el-table-column
                                        prop="pos"
                                        label="#"
                                        width="50">
                                    </el-table-column>
                                    <el-table-column
                                        prop="player"
                                        label="Player">
                                    </el-table-column>
                                    <el-table-column
                                        prop="total_count"
                                        label="Total count">
                                    </el-table-column>
                                    <el-table-column
                                        prop="in_preflop"
                                        label="In preflop">
                                    </el-table-column>
                                    <el-table-column
                                        prop="first_5_hands"
                                        label="First 5 hands">
                                    </el-table-column>
                                    <el-table-column
                                        prop="total_won"
                                        label="Total won">
                                    </el-table-column>
                                </el-table>
                            </el-col>
                        </el-row>
                        <el-row class="pb">
                            <el-col><small>
                                *)	percental value: absolute value in relation to hands played<br>
                                **)	percental value: number of hands with at least one bet/raise in relation to all hands played
                            </small></el-col>
                        </el-row>
                        <el-row>
                            <el-col><hr /></el-col>
                        </el-row>
                    </div>
		        </li>
            </ul>
        </div>
    </div>
</template>
<script>
export default {
    data () {
        return {
            game: null,
            datacollection1: null,
            datacollection2: null,
            options1: null,
            options2: null,
            most_hands: null,
            best_hands: null,
            most_wins: null,
            highest_wins: null,
            longest_wins: null,
            longest_losses: null,
            most_bets: null,
            most_bingo: null,
            basic_data: null,
            ranking: null,
            game_ids: null,
            game_id: 1,
        }
    },
    methods:{
        init(){
            let gId = this.getUrlParameter('game_id')
            if(gId === null){
                gId = ''
            }else{
                this.game_id = gId
                gId = '&game_id=' + gId
            }
            axios.get('/pthranking/game/log?pdb=' + this.getUrlParameter('pdb') + gId)
                .then(res => {
                    if(res.data.status && res.data.status == true){
                        this.game = res.data.msg
                        this.render_game()
                    }
                }).catch(err => {
                    console.log(err)
            })
        },
        getGame(){
            console.log("getGame", this.game_id);
            window.location.href = window.location.origin + '/gamelog?pdb=' + this.getUrlParameter('pdb') + '&game_id=' + this.game_id
        },
        getUrlParameter: function(key) {
            let address = window.location.search
            let parameterList = new URLSearchParams(address)
            return parameterList.get(key)
        },        
        render_game(){
            this.game_ids = []
            for(let id in this.game.game_ids){
                this.game_ids.push(
                    { value: this.game.game_ids[id], text: this.game.game_ids[id] }
                )
            }
            let colors = [
                'rgba(86, 226, 137, 1.0)',
                'rgba(104, 226, 86, 1.0)',
                'rgba(174, 226, 86, 1.0)',
                'rgba(226, 297, 86, 1.0)',
                'rgba(226, 137, 86, 1.0)',
                'rgba(226, 84, 104, 1.0)',
                'rgba(226, 86, 174, 1.0)',
                'rgba(207, 86, 226, 1.0)',
                'rgba(138, 86, 226, 1.0)',
                'rgba(86, 104, 226, 1.0)'
            ]
            // hand cash
            let labels1 = []
            for(let i=1;i<=this.game.hand_cash[0].length;i++){
                if(i === 1)  labels1.push("Hand: " + i);
                else   labels1.push(i);
            }
            let datasets1 = []
            try{
                for(let index in this.game.hand_cash){
                    if(parseInt(index) >= this.game.player_list[0].length) break;
                    let hand = this.game.hand_cash[index]
                    let data = []
                    for(let j=0;j<=hand.length;j++){
                        data.push(Number(hand[j]));
                    }
                    let set = {
                        label: this.game.player_list[1][this.game.player_list[0].indexOf((parseInt(index) + 1).toString())],
                        borderColor: colors[parseInt(index)],
                        data: data
                    }
                    datasets1.push(set);
                }
            }catch(e){
                console.log(e)
            }
            this.datacollection1 = {
                labels: labels1,
                datasets: datasets1
            }
            // pot size
            let labels2 = []
            let data2 = []
            for(let i=0;i<this.game.pot_size[0].length;i++){
                data2.push(100000 - Number(this.game.pot_size[0][i]));
                labels2.push(labels1[i])
            }
            let set2 =[{
                borderColor: colors[0],
                data: data2,
                label: 'Poz Size'
            }]
            this.datacollection2 = {
                labels: labels2,
                datasets: set2
            },
            // options
            this.options1 = {
                scales: {
                    yAxes: [{
                        ticks: {
                            beginAtZero: true,
                            min: 0
                        }
                    }]
                },
                responsive: true,
                maintainAspectRatio: false
            },
            this.options2 = {
                scales: {
                    yAxes: [{
                        ticks: {
                            beginAtZero: true,
                            min: 0
                        }
                    }]
                },
                legend: {
                    display: false
                },
                responsive: true,
                maintainAspectRatio: false
            }
            // console.log(this.game['most hands played'])
            this.most_hands = []
            for(let i=0;i<this.game['most hands played'][0].length;i++){
                this.most_hands.push(
                    {
                        pos: i+1,
                        player: this.game['most hands played'][1][i],
                        count: Math.round(this.game['most hands played'][4][i]) +
                                '% (' + this.game['most hands played'][2][i] + '/' + this.game['most hands played'][3][i] + ' hands)',
                        _10_to_7_player: Math.round(this.game['most hands played'][7][i]) +
                                '% (' + this.game['most hands played'][5][i] + '/' + this.game['most hands played'][6][i] + ')',
                        _6_to_4_player: Math.round(this.game['most hands played'][10][i]) +
                                '% (' + this.game['most hands played'][8][i] + '/' + this.game['most hands played'][9][i] + ')',
                        _3_to_1_player: Math.round(this.game['most hands played'][13][i]) +
                                '% (' + this.game['most hands played'][11][i] + '/' + this.game['most hands played'][12][i] + ')',
                    }
                )
            }
            this.best_hands = []
            for(let i=0;i<this.game['best hands'][0].length;i++){
                this.best_hands.push(
                    {
                        pos: i+1,
                        cards: this.game['best hands'][2][i],
                        player: this.game['best hands'][1][i],
                        hand: this.game['best hands'][3][i],
                        result: this.game['best hands'][4][i]
                    }
                )
            }
            this.most_wins = []
            for(let i=0;i<this.game['most wins'][0].length;i++){
                this.most_wins.push(
                    {
                        pos: i+1,
                        player: this.game['most wins'][1][i],
                        'count *': this.game['most wins'][2][i] + ' (' + Math.round(this.game['most wins'][3][i]) + '%)',
                        highest: '$' + this.game['most wins'][4][i]
                    }
                )
            }
            this.highest_wins = []
            for(let i=0;i<this.game['highest wins'][0].length;i++){
                this.highest_wins.push(
                    {
                        pos: i+1,
                        amount: '$' + this.game['highest wins'][4][i],
                        player: this.game['highest wins'][1][i],
                        hand: this.game['highest wins'][2][i] + ((this.game['highest wins'][3][i]) ? ' (side pot)' : ''),
                    }
                )
            }
            this.longest_wins = []
            for(let i=0;i<10;i++){
                this.longest_wins.push(
                    {
                        pos: i+1,
                        duration: this.game['longest series of wins'][2][i],
                        player: this.game['longest series of wins'][1][i],
                        hands: this.game['longest series of wins'][3][i] + '-' + this.game['longest series of wins'][4][i],
                        total_gain: this.game['longest series of wins'][5][i],
                    }
                )
            }
            this.longest_losses = []
            for(let i=0;i<10;i++){
                this.longest_losses.push(
                    {
                        pos: i+1,
                        duration: this.game['longest series of losses'][2][i],
                        player: this.game['longest series of losses'][1][i],
                        hands: this.game['longest series of losses'][3][i] + '-' + this.game['longest series of losses'][4][i],
                        total_loss: '$' + this.game['longest series of losses'][5][i],
                    }
                )
            }
            this.most_bets = []
            for(let i=0;i<this.game['most bet/raise'][0].length;i++){
                this.most_bets.push(
                    {
                        pos: i+1,
                        player: this.game['most bet/raise'][1][i],
                        'Count **': this.game['most bet/raise'][2][i] + ' (' + Math.round(this.game['most bet/raise'][4][i]) + '%)',
                    }
                )
            }
            this.most_bingo = []
            for(let i=0;i<this.game['most all in'][0].length;i++){
                this.most_bingo.push(
                    {
                        pos: i+1,
                        player: this.game['most all in'][1][i],
                        total_count: this.game['most all in'][2][i] + ' (' + Math.round(this.game['most all in'][3][i]) + '%)',
                        in_preflop: this.game['most all in'][4][i],
                        first_5_hands: this.game['most all in'][5][i],
                        total_won: this.game['most all in'][6][i],
                    }
                )
            }
            this.ranking = []
            for(let i=0;i<this.game['player_list'][0].length;i++){
                let eliminated = this.game['player_list'][7][i][0]
                if(typeof eliminated !== 'undefined'){
                    if(eliminated.indexOf('[') == -1){
                        eliminated = 'eliminated by ' + eliminated
                    }else{
                        eliminated = 'wins with ' + eliminated
                    }
                }else{
                    eliminated = ''
                }
                this.ranking.push(
                    {
                        pos: i+1,
                        player: this.game['player_list'][1][i],
                        hand: this.game['player_list'][3][i],
                        _: eliminated
                    }
                )
            }
            this.types.map(typ => { 
                if(typ.value == this.game.type) this.type = typ.text 
            })
        },
        rowClass(item, type) {
            if (!item || type !== 'row') return
            // console.log("rowClass", item, type)
            return 'cplink'
        },
        rowAttr(item, type) {
            if (!item || type !== 'row') return
            return { "data-player": item.player }
        },
        rowClick(item, index, event){
            window.open(window.location.origin + '/player?u=' + item.player, '_blank')
        },
    },
    mounted(){
        this.init()
    },
}
</script>
<style lang="scss">
.content ul, .content ol {
    margin: 0!important;
}
ul.forums, ul.topics {
    padding: 0;
}
.el-select-dropdown{
    border-color: #EBEEF5;
    .el-select-dropdown__item.hover, .el-select-dropdown__item:hover{
        background-color: #fff;
    }
}
.game-log{
s    .el-input__inner{
        background-color: transparent;
    }

    .el-select .el-input__inner, .el-input{
        &:focus,&:hover, &.is-focus .el-input__inner{
            border-color: #383c44;
        }
    }

    .pb{
        padding-bottom: 1em;
    }
    h3{
        padding-bottom: 0.5em;
    }
    .el-table{
        background-color: transparent!important;
        td, th{
            padding: 0.2em 0;
            border-bottom: 0;
        }
        tr{
            &:last-child{
                td, th{
                    padding: 0 0 0.3em 0;
                }
            }
        }
        &.ranking{
            tr{
                cursor: pointer;
            }
        }
        th {
            background-color: transparent!important;
            border-bottom: 0;
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
        .el-table__header-wrapper{
            border-bottom: 1px solid #EBEEF5!important;
        }
    }
}
.fd_dark{
    .popper__arrow, .popper__arrow::after, .popper__arrow *{
        border-bottom-color: #242a36!important;
    }
    .el-select-dropdown{
        border-color: #242a36;
        background-color: #242a36;
        .el-select-dropdown__item{
            background-color: #242a36;
        }
    }
    .game-log{
        li.row:hover{
            background-color: #242a36!important;
        }
    }
}
</style>