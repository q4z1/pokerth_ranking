<template>
    <div class="row" v-if="champions">
        <div class="col cod">
            <div class="card">
                <div class="card-body">
                    <h2>Champions of the Day</h2>
                    <ol>
                        <li><a :href="champions[0]['url']" v-html="champions[0]['username']" target="_blank"></a></li>
                        <li><a :href="champions[1]['url']" v-html="champions[1]['username']" target="_blank"></a></li>
                        <li><a :href="champions[2]['url']" v-html="champions[2]['username']" target="_blank"></a></li>
                    </ol>
                </div>
            </div>
            <hr />
        </div>
    </div>
</template>
<script>
export default {
    data: function() { 
        return {
            champions: false,
        }
    },
    mounted () {
        this.getCOD()
    },
    methods: {
        getCOD: function(){
            // console.log('getCOD')
            axios.get('/pthranking/ranking/cod')
                .then(res => {
                    this.champions = res.data
                }).catch(err => {
                    console.log(err)
                    this.champions = false
            })
        }
    }
}
</script>
<style lang="scss" scoped>
    .col.cod{
        margin-bottom: 30px;
        margin-left: auto;
        margin-right: auto;
        width: 90%;

        * {
            background-color: transparent!important;
        }

        ol{
            list-style-position: inside;
            margin: 0;
            padding: 0;
            li{
                text-align: left;
                font-size: larger;
            }
        }
    }
</style>