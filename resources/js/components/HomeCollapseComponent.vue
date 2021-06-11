<template>
    <div v-if="html">
        <b-collapse id="home-collapse" :visible="visible">
            <b-button class="close" @click="closeCollapse">x</b-button>
            <div v-html="html"></div>
        </b-collapse>
    </div>    
</template>
<script>
export default {
    data() {
        return {
            visible: true,
            html: false
        }
    },
    mounted() {
        let p = window.location.pathname
        if((p === '/' || p.indexOf('index.php') != -1) && this.getCookie('closeHomeCollapse') === null){
            axios.get('/pthranking/html/welcome')
            .then(res => {
                if(typeof res.data.success !== 'undefined' && res.data.success === true){
                    this.html =res.data.html
                    this.visible = true
                }else{
                    console.log(res.data)
                }

            }).catch(err => {
                console.log(err)
            })
        }
    },
    methods: {
        closeCollapse(evt) {
            console.log('closeCollapse evt', evt)
            let exp = new Date();
            exp.setTime(exp.getTime() + 1000 * 604800);
            window.document.cookie = 'closeHomeCollapse=true; expires='+exp.toUTCString()+';path=/'
            this.visible = false
        },
        getCookie(name) {
            let re = new RegExp(name + "=([^;]+)");
            let value = re.exec(window.document.cookie);
            return (value != null) ? unescape(value[1]) : null;
        },
    }
}
</script>
<style lang="scss">
#home-collapse{

    background-color: #2d3039;
    margin: 1em 0;
    padding: 1em;
    position: relative;
    color: #bec4c9;
    h1, h2, h3, h4, h5, h6{
        font-weight: normal;
        padding-bottom: 1.2em;
    }
    button.close{
        position: absolute;
        top: 0.15em;
        right: 0.15em;
        width: 1.2em;
        height: 1.2em;
        font-weight: normal;
        font-size: 1.4em;
        vertical-align: middle;
        &:hover{
            color: #838b98;
        }
    }
}
.fd_dark #home-collapse{
    background-color: #242a36;
    color: #838b98;
    border: 1px solid rgba(255, 255, 255, 0.04);
    button.close{
        &:hover{
            color: #bec4c9;
        }
    }
}
</style>