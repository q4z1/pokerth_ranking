<template>
    <div class="downloads">
        <div class="inner">
            <ul class="topiclist">
                <li class="header">
                    <dl class="row-item">
                        <dt><div class="list-inner">
                            Card Decks & Table Styles
                        </div></dt>
                    </dl>
                </li>
            </ul>
            <ul class="topiclist forums">
                <li class="row styles-row" v-for="(pair, index) in pairedRows" :key="index">
                    <div class="list-inner">
                        <el-row style="display: flex;">
                            <el-col :span="12" class="styles-col" :class="{ 'styles-col--empty': !pair.card }">
                                <template v-if="pair.card">
                                    <a :href="'/download/styles/cards/' + pair.card.filename" :title="pair.card.filename" class="styles-entry">
                                        <div class="styles-img"><img v-if="pair.card.preview" :src="pair.card.preview"></div>
                                        <span>{{ pair.card.filename }}</span>
                                    </a>
                                </template>
                            </el-col>
                            <el-col :span="12" class="styles-col" :class="{ 'styles-col--empty': !pair.table }">
                                <template v-if="pair.table">
                                    <a :href="'/download/styles/table/' + pair.table.filename" :title="pair.table.filename" class="styles-entry">
                                        <div class="styles-img"><img v-if="pair.table.preview" :src="pair.table.preview"></div>
                                        <span>{{ pair.table.filename }}</span>
                                    </a>
                                </template>
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
                cards: null,
                tables: null,
            }
        },
        computed: {
            pairedRows() {
                const cards = this.cards || []
                const tables = this.tables || []
                const len = Math.max(cards.length, tables.length)
                const result = []
                for (let i = 0; i < len; i++) {
                    result.push({ card: cards[i] || null, table: tables[i] || null })
                }
                return result
            }
        },
        mounted() {
            this.getStyles()
        },
        methods: {
            getStyles: function(){
                axios.get('/pthranking/styles')
                .then(res => {
                    if(res.data.status){
                        this.cards = res.data.cards
                        this.tables = res.data.tables
                    }
                }).catch(err => {
                    console.log(err)
                })
            },
        }
    }
</script>
<style lang="scss">
.downloads {
    .styles-row {
        padding: 0 !important;

        .list-inner {
            padding: 0 !important;
        }

        .el-row {
            align-items: stretch;
        }
    }

    .styles-col {
        height: 160px;
        border-right: 1px solid rgba(255,255,255,0.08);

        &:last-child {
            border-right: none;
        }

        &--empty {
            background: transparent;
        }
    }

    .styles-entry {
        display: flex;
        align-items: center;
        height: 100%;
        padding: 0.5em 0.75em;
        text-decoration: none;
        transition: background 0.15s;

        &:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .styles-img {
            flex-shrink: 0;
            width: 160px;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;

            img {
                max-width: 160px;
                max-height: 140px;
                width: auto;
                height: auto;
                object-fit: contain;
            }
        }

        span {
            margin-left: 0.75em;
            word-break: break-all;
        }
    }

    p {
        margin-top: 1em;
        text-align: center;
    }
}
</style>