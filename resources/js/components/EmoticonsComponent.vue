<template>
    <div class="emoticons">
        <div class="inner">
            <ul class="topiclist">
                <li class="header">
                    <dl class="row-item">
                        <dt><div class="list-inner">
                            Emoticons
                        </div></dt>
                    </dl>
                </li>
            </ul>
            <ul class="topiclist forums">
		        <li class="row">
                    <div class="list-inner">
                        <el-row>
                            <el-col>
                                <data-tables v-if="emoticons" :data="emoticons" layout="table">
                                    <el-table-column prop="title" label="Title">
                                        <template #default="{ row }">
                                            {{ row.title }}
                                        </template>
                                    </el-table-column>
                                    <el-table-column prop="char" label="Characters">
                                        <template #default="{ row }">
                                            {{ row.char }}
                                        </template>
                                    </el-table-column>
                                    <el-table-column prop="emoticon" label="Emoticon">
                                        <template #default="{ row }">
                                            <img :src="'/images/emoticons/' + row.title + '.png'" :alt="row.title"/>
                                        </template>
                                    </el-table-column>
                                </data-tables>
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
        data: function() { 
            return {
                emoticons: [
                    { title: 'face-wink', char: ';)' },
                    { title: 'face-wink', char: ';-)' },
                    { title: 'face-smile', char: ':)' },
                    { title: 'face-smile', char: ':-)' },
                    { title: 'face-worried', char: ':-s' },
                    { title: 'face-worried', char: ':-S' },
                    { title: 'face-angel', char: ':0:-)' },
                    { title: 'face-angry', char: 'X-(' },
                    { title: 'face-cool', char: 'B-)' },
                    { title: 'face-cool', char: '8-)' },
                    { title: 'face-crying', char: ':\'(' },
                    { title: 'face-devilish', char: '>:-)' },
                    { title: 'face-embarrassed', char: ':-[' },
                    { title: 'face-kiss', char: ':-*' },
                    { title: 'face-laugh', char: ':))' },
                    { title: 'face-laugh', char: ':-))' },
                    { title: 'face-plain', char: ':-|' },
                    { title: 'face-raspberry', char: ':-P' },
                    { title: 'face-raspberry', char: ':-p' },
                    { title: 'face-sad', char: ':-(' },
                    { title: 'face-sad', char: ':(' },
                    { title: 'face-sick', char: ':-&' },
                    { title: 'face-smile-big', char: ':-D' },
                    { title: 'face-smile-big', char: ':D' },
                    { title: 'face-smirk', char: ':-!' },
                    { title: 'face-surprise', char: ':-0' },
                    { title: 'face-surprise', char: ':-O' },
                    { title: 'face-surprise', char: ':-o' },
                    { title: 'face-uncertain', char: ':-/' },
                ]
            }
        },
        mounted() {

        },
        methods: {

        }
    }
</script>
<style lang="scss">
.el-select-dropdown{
    .el-select-dropdown__item.selected {
        color: inherit!important;
    }
}
.emoticons{
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
                &:hover{
                    background-color: #c7cad0!important;
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
.fd_dark .emoticons{
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