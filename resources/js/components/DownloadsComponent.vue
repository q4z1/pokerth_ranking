<template>
    <div class="downloads">
        <div class="inner">
            <ul class="topiclist">
                <li class="header">
                    <dl class="row-item">
                        <dt><div class="list-inner">
                            Downloads
                        </div></dt>
                    </dl>
                </li>
            </ul>
            <ul class="topiclist forums">
		        <li class="row">
                    <div class="list-inner">
                        <el-row>
                            <el-col>
                                <h3>PokerTH 2.0:</h3>
                            </el-col>
                        </el-row>
                        <el-row>
                            <el-col>
                                <el-collapse>
                                    <el-collapse-item title="MD5SUMS" name="1">
                                        <div v-html="new_md5"></div>
                                    </el-collapse-item>
                                </el-collapse>                                
                            </el-col>
                        </el-row>
                        <el-row>
                            <el-col v-if="new_files">
                                <el-table
                                    :data="new_files"
                                    :show-header="false"
                                    style="width: 100%">
                                    <el-table-column
                                    label="File">
                                    <template slot-scope="scope">
                                        <el-row style="display: flex; align-items: center">
                                            <el-col :span="2"><img v-if="scope.row.icon" :src="scope.row.icon" width="48"></el-col>
                                            <el-col :span="22" style="margin-left: 0.4em;"><a :href="'/download/client/current/' + scope.row.filename" :title="scope.row.filename">{{ scope.row.filename }}</a></el-col>
                                        </el-row>
                                        
                                    </template>
                                    </el-table-column>
                                </el-table>
                            </el-col>
                        </el-row>
                        <hr />
                        <el-row>
                            <el-col style="margin-top: 0.4em;">
                                <h3>PokerTH 1.1.2:</h3>
                            </el-col>
                        </el-row>
                        <el-row>
                            <el-col>
                                <el-collapse>
                                    <el-collapse-item title="MD5SUMS" name="1">
                                        <div v-html="old_md5"></div>
                                    </el-collapse-item>
                                </el-collapse>                                
                            </el-col>
                        </el-row>
                        <el-row>
                            <el-col v-if="old_files">
                                <el-table
                                    :data="old_files"
                                    :show-header="false"
                                    style="width: 100%">
                                    <el-table-column
                                    label="File">
                                    <template slot-scope="scope">
                                        <el-row style="display: flex; align-items: center">
                                            <el-col :span="2"><img v-if="scope.row.icon" :src="scope.row.icon" width="48"></el-col>
                                            <el-col :span="22" style="margin-left: 0.4em;"><a :href="'/download/client/old/' + scope.row.filename" :title="scope.row.filename">{{ scope.row.filename }}</a></el-col>
                                        </el-row>
                                        
                                    </template>
                                    </el-table-column>
                                </el-table>
                            </el-col>
                        </el-row>
                        <el-row>
                            <el-col>
                                <p>Checkout <a title="sourceforge" href="https://sourceforge.net/projects/pokerth/files/pokerth/" target="_blank"><i>sourceforge</i></a> for other Versions.</p>
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
                old_files: null,
                new_files: null,
                old_md5: null,
                new_md5: null,
            }
        },
        mounted() {
            this.getOldFiles()
            this.getNewFiles()
        },
        methods: {
            getOldFiles: function(){
                axios.get('/pthranking/downloads/old')
                .then(res => {
                    if(res.data.status){
                        this.old_files = res.data.files
                        this.old_md5 = res.data.md5
                    }
                }).catch(err => {
                    console.log(err)
                })
            },
             getNewFiles: function(){
                axios.get('/pthranking/downloads/current')
                .then(res => {
                    if(res.data.status){
                        this.new_files = res.data.files
                        this.new_md5 = res.data.md5
                    }
                }).catch(err => {
                    console.log(err)
                })
            }
        }
    }
</script>
<style lang="scss">
.downloads{
    .forums li{
        margin-bottom: 1em !important;
    }
    .el-collapse{
        background: transparent !important;
        border-bottom: 0!important;
        border-top: 0!important;
        border-color: transparent!important;
        border-width: 0!important;
        .el-collapse-item{
            .el-collapse-item__header{
                background-color: transparent !important;
                color: inherit !important;
                font-size: 1em !important;
                cursor: pointer!important;
                border-bottom: none !important;
            }
            .el-collapse-item__content {
                padding-bottom: 0.6em !important;
                color: inherit !important;
            }
            .el-collapse-item__wrap {
                border-bottom: 0!important;
                background-color: transparent !important;
            }
        }
    }
    .el-table{
        .cell{
            padding-left: 0 !important;
        }
        th {
            background-color: transparent!important;
        }
        tr{
            background-color: transparent!important;
            &:hover{
                background-color: transparent !important;
            }
            td{
                background-color: transparent!important;
                &:hover{
                    background-color: transparent!important;
                }
            }
        }
        td, th{
            border-bottom: none;
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
        &::after, &::before {
            background-color: transparent !important;
        }
    }
    p {
        margin-top: 1em;
        text-align: center;
    }
}
.fd_dark .downloads{
    .el-table{
        background-color: transparent!important;
        tr{
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
}
</style>