<template>
    <div>
        <el-row v-if="champions">
            <el-col class="cod">
                <h2>Champions of the Day</h2>
                <ol>
                    <li><a :href="champions[0]['url']" v-html="champions[0]['username']" target="_blank"></a></li>
                    <li><a :href="champions[1]['url']" v-html="champions[1]['username']" target="_blank"></a></li>
                    <li><a :href="champions[2]['url']" v-html="champions[2]['username']" target="_blank"></a></li>
                </ol>
            </el-col>
        </el-row>
        <el-row>
            <el-col><hr /></el-col>
        </el-row>
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
            axios.get('/pthranking/ranking/cod')
                .then(res => {
                    this.champions = (res.data.length) ? res.data : false
                }).catch(err => {
                    console.log(err)
                    this.champions = false
            })
        }
    }
}
</script>
<style lang="scss" scoped>
    .el-col.cod{
        * {
            background-color: transparent!important;
        }
        width: 86%;
        margin: 0 7%;
        text-align: center;
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