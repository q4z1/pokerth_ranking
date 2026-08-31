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
                        <template v-for="(version, index) in versions" :key="index">
                            <el-row>
                                <el-col :style="index > 0 ? 'margin-top: 0.4em;' : ''">
                                    <div style="display:flex;align-items:baseline;flex-wrap:wrap;gap:.25em .8em;margin-bottom:.5em;">
                                        <h3 style="margin:0;">PokerTH {{ version.version }}:</h3>
                                        <span v-if="version.source === 'github'" style="opacity:.75;font-size:.8em;white-space:nowrap;display:inline-flex;align-items:center;">
                                            <svg viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" style="margin-right:.35em;flex-shrink:0;fill:currentColor;">
                                                <path d="M8 0c4.42 0 8 3.58 8 8a8.013 8.013 0 0 1-5.45 7.59c-.4.08-.55-.17-.55-.38 0-.27.01-1.13.01-2.2 0-.75-.25-1.23-.54-1.48 1.78-.2 3.65-.88 3.65-3.95 0-.88-.31-1.59-.82-2.15.08-.2.36-1.02-.08-2.12 0 0-.67-.22-2.2.82-.64-.18-1.32-.27-2-.27-.68 0-1.36.09-2 .27-1.53-1.03-2.2-.82-2.2-.82-.44 1.1-.16 1.92-.08 2.12-.51.56-.82 1.28-.82 2.15 0 3.06 1.86 3.75 3.64 3.95-.23.2-.44.55-.51 1.07-.46.21-1.61.55-2.33-.66-.15-.24-.6-.83-1.23-.82-.67.01-.27.38.01.53.34.19.73.9.82 1.13.16.45.68 1.31 2.69.94 0 .67.01 1.3.01 1.49 0 .21-.15.45-.55.38A7.995 7.995 0 0 1 0 8c0-4.42 3.58-8 8-8Z"></path>
                                            </svg>
                                            Downloads are served from&nbsp;<a :href="version.github_url" target="_blank" rel="noopener">GitHub Releases</a>.
                                        </span>
                                        <span v-if="version.published_at" style="opacity:.55;font-size:.8em;white-space:nowrap;">released {{ version.published_at.slice(0, 10) }}</span>
                                    </div>
                                </el-col>
                            </el-row>
                            <el-row>
                                <el-col>
                                    <el-collapse>
                                        <el-collapse-item title="SHA256" :name="'sha-' + index">
                                            <div v-html="version.sha256"></div>
                                        </el-collapse-item>
                                    </el-collapse>
                                </el-col>
                            </el-row>
                            <el-row v-if="version.readme">
                                <el-col>
                                    <el-collapse>
                                        <el-collapse-item title="README" :name="'readme-' + index">
                                            <div v-html="version.readme"></div>
                                        </el-collapse-item>
                                    </el-collapse>                                
                                </el-col>
                            </el-row>
                            <el-row>
                                <el-col v-if="version.files && version.files.length > 0">
                                    <el-table
                                        :data="version.files"
                                        :show-header="false"
                                        style="width: 100%">
                                        <el-table-column
                                        label="File"
                                        width="auto">
                                        <template #default="scope">
                                            <div style="display:flex;align-items:center;gap:0.6em;">
                                                <img v-if="scope.row.icon" :src="scope.row.icon" style="width:36px;height:36px;object-fit:contain;flex-shrink:0;" />
                                                <span v-else style="width:36px;flex-shrink:0;"></span>
                                                <a :href="scope.row.url" :title="scope.row.sha256 ? 'SHA256: ' + scope.row.sha256 : scope.row.filename">{{ scope.row.filename }}</a>
                                                <span v-if="scope.row.size" style="margin-left:auto;white-space:nowrap;opacity:.7;font-size:.9em;">{{ formatSize(scope.row.size) }}</span>
                                            </div>
                                        </template>
                                        </el-table-column>
                                    </el-table>
                                </el-col>
                            </el-row>
                            <el-row v-if="version.releases_url">
                                <el-col style="font-size:.8em;opacity:.7;margin-top:.4em;">
                                    Looking for an older version? <a :href="version.releases_url" target="_blank" rel="noopener">All releases on GitHub</a>.
                                </el-col>
                            </el-row>
                            <hr v-if="index < versions.length - 1" />
                        </template>
                        <hr style="margin-top: 1em;" />
                        <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-around;gap:12px;">
                            <a href="https://sourceforge.net/projects/pokerth/files/pokerth/" target="_blank">
                                <img alt="Download PokerTH"
                                    src="https://a.fsdn.com/con/app/sf-download-button"
                                    width="299" height="52"
                                    style="height:52px;width:299px;display:block;" />
                            </a>

                            <a href="https://flathub.org/apps/net.pokerth.PokerTH" target="_blank">
                                <img style="height:52px;width:156px;display:block;"
                                    width="156" height="52"
                                    alt="Get it on Flathub"
                                    src="https://flathub.org/api/badge?locale=en"/>
                            </a>

                            <a href="https://snapcraft.io/pokerth" target="_blank">
                                <img style="height:52px;width:169px;display:block;"
                                    width="169" height="52"
                                    alt="Get it from the Snap Store"
                                    src="https://snapcraft.io/en/dark/install.svg" />
                            </a>

                            <div style="
                                    display:inline-flex;
                                    align-items:center;
                                    justify-content:space-between;
                                    flex-shrink:0;
                                    background:#111;
                                    color:#fff;
                                    border-radius:8px;
                                    padding:8px 14px;
                                    width:230px;
                                    height:52px;
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

                            <webclient-button-component></webclient-button-component>
                        </div>
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
                versions: [],
                wingetCopied: false
            }
        },
        mounted() {
            this.getAllVersions()
        },
        methods: {
            formatSize: function(bytes){
                if(!bytes) return ''
                const mb = bytes / 1048576
                return mb >= 1 ? mb.toFixed(0) + ' MB' : (bytes / 1024).toFixed(0) + ' KB'
            },
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