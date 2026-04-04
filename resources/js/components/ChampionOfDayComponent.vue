<template>
    <div>
        <el-row v-if="champions && champions.length > 0">
            <el-col class="cod">
                <h2>Champions of the Day</h2>
                <ol>
                    <li v-for="(champion, i) in champions.slice(0, 3)" :key="i">
                        <a :href="champion['url']" v-html="champion['username']" target="_blank"></a>
                    </li>
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
        h2 {
            text-align: left;
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