<template>
    <div class="container-fluid player">
        <div class="row justify-content-center">
            <div class="col-md-12">
                
                <div v-if="player" class="card">
                    <div class="card-header">
                        <h2>{{ player.username }}</h2>
                    </div>

                    <div class="card-body">

                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
<script>
    export default {
        components: {

        },
        data: function() { 
            return {
                player: false 
            }
        },
        mounted() {
            this.getPlayer()

        },
        methods: {
            getPlayer: function(event){
                axios.get('/pthranking/player/show?player_id=1')
                    .then(res => {
                        if(res.data.status){
                            this.player = res.data.msg
                        }else{
                            console.log(res.data.msg)
                            this.player = false
                        }
                    }).catch(err => {
                        console.log(err)
                        this.player = false
                })
            },
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