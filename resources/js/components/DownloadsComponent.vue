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
                        <template v-for="(version, index) in versions">
                            <el-row :key="'title-' + index">
                                <el-col :style="index > 0 ? 'margin-top: 0.4em;' : ''">
                                    <h3>PokerTH {{ version.version }}:</h3>
                                </el-col>
                            </el-row>
                            <el-row :key="'md5-' + index">
                                <el-col>
                                    <el-collapse>
                                        <el-collapse-item title="MD5SUMS" :name="'md5-' + index">
                                            <div v-html="version.md5"></div>
                                        </el-collapse-item>
                                    </el-collapse>                                
                                </el-col>
                            </el-row>
                            <el-row :key="'readme-' + index" v-if="version.readme">
                                <el-col>
                                    <el-collapse>
                                        <el-collapse-item title="README" :name="'readme-' + index">
                                            <div v-html="version.readme"></div>
                                        </el-collapse-item>
                                    </el-collapse>                                
                                </el-col>
                            </el-row>
                            <el-row :key="'files-' + index">
                                <el-col v-if="version.files && version.files.length > 0">
                                    <el-table
                                        :data="version.files"
                                        :show-header="false"
                                        style="width: 100%">
                                        <el-table-column
                                        label="File"
                                        width="auto">
                                        <template slot-scope="scope">
                                            <el-row style="display: flex; align-items: center">
                                                <el-col :span="2"><img v-if="scope.row.icon" :src="scope.row.icon" width="48"></el-col>
                                                <el-col :span="22" style="margin-left: 0.4em;">
                                                    <a :href="'/download/client/' + version.version + '/' + scope.row.filename" :title="scope.row.filename">{{ scope.row.filename }}</a>
                                                </el-col>
                                            </el-row>
                                        </template>
                                        </el-table-column>
                                        <el-table-column
                                        label="Datum"
                                        width="140"
                                        align="right">
                                        <template slot-scope="scope">
                                            <span class="file-date">{{ scope.row.date }}</span>
                                        </template>
                                        </el-table-column>
                                    </el-table>
                                </el-col>
                            </el-row>
                            <hr :key="'hr-' + index" v-if="index < versions.length - 1" />
                        </template>
                        <hr style="margin-top: 1em;" />
                        <el-row :gutter="20" align="middle">
                            <el-col :span="6" style="display:flex;justify-content:center;">
                                <a href="https://sourceforge.net/projects/pokerth/files/pokerth/" target="_blank">
                                    <img alt="Download PokerTH"
                                        src="https://a.fsdn.com/con/app/sf-download-button"
                                        width="280" height="52" />
                                </a>
                            </el-col>

                            <el-col :span="6" style="display:flex;justify-content:center;">
                                <a href="https://flathub.org/apps/net.pokerth.PokerTH" target="_blank">
                                    <img height="56"
                                        alt="Get it on Flathub"
                                        src="https://flathub.org/api/badge?locale=en"/>
                                </a>
                            </el-col>

                            <el-col :span="6" style="display:flex;justify-content:center;">
                                <a href="https://snapcraft.io/pokerth" target="_blank">
                                    <img height="56"
                                        alt="Get it from the Snap Store"
                                        src="https://snapcraft.io/en/dark/install.svg" />
                                </a>
                            </el-col>

                            <el-col :span="6" style="display:flex;justify-content:center;">
                                <div style="
                                    display:inline-flex;
                                    align-items:center;
                                    justify-content:space-between;
                                    background:#111;
                                    color:#fff;
                                    border-radius:8px;
                                    padding:8px 14px;
                                    width:230px;
                                    height:56px;
                                    box-sizing:border-box;
                                    font-family:system-ui, sans-serif;
                                ">

                                    <svg width="36" height="36" viewBox="0 0 24 24" fill="white">
                                        <path d="M3 3h8v8H3V3zm10 0h8v8h-8V3zM3 13h8v8H3v-8zm10 4h8v4h-8v-4z"/>
                                    </svg>

                                    <div style="flex:1;text-align:left;margin-left:10px;margin-top:3px;">
                                        <div style="font-size:10px;opacity:.75;line-height:1;letter-spacing:.05em;">
                                            INSTALL WITH
                                        </div>
                                        <div style="font-size:17px;font-weight:600;line-height:1.2">
                                            <el-tooltip content="Install PokerTH via Windows Package Manager">
                                                <a href="https://github.com/microsoft/winget-pkgs" target="_blank">winget</a>
                                            </el-tooltip>
                                        </div>
                                    </div>

                                    <button
                                        @click="copyWinget"
                                        style="
                                            background:#2a2a2a;
                                            border:none;
                                            color:#fff;
                                            padding:5px 10px;
                                            border-radius:5px;
                                            font-size:11px;
                                            cursor:pointer;
                                        "
                                    >
                                        {{ wingetCopied ? '✔ copied' : 'copy' }}
                                    </button>

                                </div>
                            </el-col>
                        </el-row>
                        <hr style="margin-top: 1em; margin-bottom: 1.5em;" />
                       <template v-for="(version, index) in tracker">
                            <el-row :key="'title-' + index">
                                <el-col :style="index > 0 ? 'margin-top: 0.4em;' : ''">
                                    <h3>PokerTH Tracker {{ version.version }} (by ollika) - see <a href="https://www.pokerth.net/viewtopic.php?t=1138" target="_blank">forum thread</a>:</h3>
                                </el-col>
                            </el-row>
                            <el-row :key="'md5-' + index">
                                <el-col>
                                    <el-collapse>
                                        <el-collapse-item title="MD5SUMS" :name="'md5-' + index">
                                            <div v-html="version.md5"></div>
                                        </el-collapse-item>
                                    </el-collapse>                                
                                </el-col>
                            </el-row>
                            <el-row :key="'readme-' + index" v-if="version.readme">
                                <el-col>
                                    <el-collapse>
                                        <el-collapse-item title="README" :name="'readme-' + index">
                                            <div v-html="version.readme"></div>
                                        </el-collapse-item>
                                    </el-collapse>                                
                                </el-col>
                            </el-row>
                            <el-row :key="'files-' + index">
                                <el-col v-if="version.files && version.files.length > 0">
                                    <el-table
                                        :data="version.files"
                                        :show-header="false"
                                        style="width: 100%">
                                        <el-table-column
                                        label="File"
                                        width="auto">
                                        <template slot-scope="scope">
                                            <el-row style="display: flex; align-items: center">
                                                <el-col :span="2"><img v-if="scope.row.icon" :src="scope.row.icon" width="48"></el-col>
                                                <el-col :span="22" style="margin-left: 0.4em;">
                                                    <a :href="'/download/tracker/' + version.version + '/' + scope.row.filename" :title="scope.row.filename">{{ scope.row.filename }}</a>
                                                </el-col>
                                            </el-row>
                                        </template>
                                        </el-table-column>
                                        <el-table-column
                                        label="Datum"
                                        width="140"
                                        align="right">
                                        <template slot-scope="scope">
                                            <span class="file-date">{{ scope.row.date }}</span>
                                        </template>
                                        </el-table-column>
                                    </el-table>
                                </el-col>
                            </el-row>
                            <hr :key="'hr-' + index" v-if="index < versions.length - 1" />
                        </template>
		        </li>
            </ul>
        </div>
    </div>
</template>
<script>
    export default {
        data: function() { 
            return {
                versions: [],
                tracker: [],
                wingetCopied: false
            }
        },
        mounted() {
            this.getAllVersions()
            this.getTrackerVersions()
        },
        methods: {
            getAllVersions: function(){
                axios.get('/pthranking/downloads/all')
                .then(res => {
                    if(res.data.status){
                        this.versions = res.data.versions
                    }
                }).catch(err => {
                    console.log(err)
                })
            },
            getTrackerVersions: function(){
                axios.get('/pthranking/downloads/tracker')
                .then(res => {
                    if(res.data.status){
                        this.tracker = res.data.versions
                    }
                }).catch(err => {
                    console.log(err)
                })
            },
              copyWinget() {
                navigator.clipboard.writeText('winget install PokerTH.PokerTH')
                this.wingetCopied = true
                setTimeout(() => {
                this.wingetCopied = false
                }, 1500)
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
                font-family: monospace;
                font-size: 0.95em;
                line-height: 1.4;
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
    .file-date {
        color: #999;
        font-size: 0.9em;
    }
}
.fd_dark .downloads{
    .file-date {
        color: #aaa;
    }
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