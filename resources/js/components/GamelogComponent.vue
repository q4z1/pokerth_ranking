<template>
    <div>
        <b-container v-if="game">
            <b-row class="mt-3">
                <b-col class="mt-3">
                    <h3>Basic data</h3>
                    <!-- <b-row>
                        <b-col><strong>Unique Game ID:</strong></b-col>
                        <b-col>#{{ game.uid }}</b-col>
                    </b-row>
                    <b-row>
                        <b-col><strong>Date/Time:</strong></b-col>
                        <b-col>{{ game.started }}</b-col>
                    </b-row> -->
                    <b-row>
                        <b-col><strong>Winner:</strong></b-col>
                        <b-col><a :href="'/player?u=' + game['player_list'][1][0]" :title="game['player_list'][1][0]">{{ game['player_list'][1][0] }}</a></b-col>
                    </b-row>
                    <b-row>
                        <b-col><strong>Number of Players:</strong></b-col>
                        <b-col>{{ game['player_list'][0].length }}</b-col>
                    </b-row>
                    <b-row>
                        <b-col><strong>Hands:</strong></b-col>
                        <b-col>{{ game['player_list'][3][0] }}</b-col>
                    </b-row>
                </b-col>
                <b-col class="mt-3">
                    <h3>Ranking</h3>
                    <b-table id="game_ranking" striped hover :items="ranking" @row-clicked="rowClick">
                        <template #cell(_)="data">
                            <span v-html="data.value"></span>
                        </template>
                    </b-table>
                </b-col>
            </b-row>
            <b-row class="mt-3">
                <b-col>
                    <h3>Hand Cash</h3>
                    <line-chart-component :chart-data="datacollection1" :options="options1"></line-chart-component>
                </b-col>
            </b-row>
            <b-row class="mt-3">
                <b-col>
                    <h3>Pot Size</h3>
                    <bar-chart-component :chart-data="datacollection2" :options="options2"></bar-chart-component>
                </b-col>
            </b-row>
            <b-row class="mt-3">
                <b-col>
                    <h3>Most hands played</h3>
                    <b-table striped :items="most_hands"></b-table>
                </b-col>
            </b-row>
            <b-row class="mt-3">
                <b-col>
                    <h3>Best hands</h3>
                    <b-table striped :items="best_hands"></b-table>
                </b-col>
            </b-row>
            <b-row class="mt-3">
                <b-col>
                    <h3>Most wins</h3>
                    <b-table striped :items="most_wins"></b-table>
                </b-col>
            </b-row>
            <b-row class="mt-3">
                <b-col>
                    <h3>Highest wins</h3>
                    <b-table striped :items="highest_wins"></b-table>
                </b-col>
            </b-row>
            <b-row class="mt-3">
                <b-col>
                    <h3>Longest wins</h3>
                    <b-table striped :items="longest_wins"></b-table>
                </b-col>
            </b-row>
            <b-row class="mt-3">
                <b-col>
                    <h3>Longest losses</h3>
                    <b-table striped :items="longest_losses"></b-table>
                </b-col>
            </b-row>
            <b-row class="mt-3">
                <b-col>
                    <h3>Most bets/raises</h3>
                    <b-table striped :items="most_bets"></b-table>
                </b-col>
            </b-row>
            <b-row class="mt-3">
                <b-col>
                    <h3>Most all in</h3>
                    <b-table striped :items="most_bingo"></b-table>
                </b-col>
            </b-row>
            <b-row>
                <b-col><small>
                    *)	percental value: absolute value in relation to hands played<br>
                    **)	percental value: number of hands with at least one bet/raise in relation to all hands played
                </small></b-col>
            </b-row>
        </b-container>
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
        }
    },
    methods:{
        init(){
            axios.get('/pthranking/game/log?pdb=' + this.getUrlParameter('pdb'))
                .then(res => {
                    console.log(res.data)
                    if(res.data.status && res.data.status == true){
                        this.game = res.data.msg
                        console.log("game", this.game)
                        this.render_game()
                    }
                }).catch(err => {
                    console.log(err)
            })
        },
        getUrlParameter: function(key) {
            let address = window.location.search
            let parameterList = new URLSearchParams(address)
            return parameterList.get(key)
        },        
        render_game(){
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
            // console.log(this.game.hand_cash)
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
<style lang="scss" scoped>
    tbody tr{
        cursor: pointer;
    }
    table{
        overflow-x: scroll;
    }
</style>
<style>
    #app{
        position: relative;
    }
    #delete footer{
        display: none;
    }
    table > tbody > tr{
        cursor: pointer;
    }
</style>