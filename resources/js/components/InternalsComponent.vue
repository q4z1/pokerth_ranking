<template>
    <div class="internals-app">
        <header class="internals-header">
            <a class="internals-logo" href="/" title="PokerTH" target="_blank">
                <img src="/images/pokerth-template-logo_light.png" alt="PokerTH" />
            </a>

            <el-menu
                v-if="auth"
                class="internals-nav"
                :default-active="view"
                mode="horizontal"
                :ellipsis="false"
                @select="navigate"
            >
                <el-menu-item index="offenders">Repeat offenders</el-menu-item>
                <el-sub-menu index="reports">
                    <template #title>Reports</template>
                    <el-menu-item index="reports-gamename">Table names</el-menu-item>
                    <el-menu-item index="reports-avatar">Avatars</el-menu-item>
                </el-sub-menu>
                <el-menu-item index="banlist">Banlist</el-menu-item>
                <el-menu-item index="adverts">Adverts</el-menu-item>
                <el-menu-item index="serverlog">Game-Server Log</el-menu-item>
                <el-menu-item index="webserverlog">Webserver Log</el-menu-item>
            </el-menu>
            <div v-else class="internals-nav internals-nav--empty"></div>

            <div class="internals-actions">
                <el-tooltip :content="theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'" placement="bottom">
                    <el-switch
                        v-model="darkMode"
                        inline-prompt
                        :active-icon="Moon"
                        :inactive-icon="Sunny"
                        class="internals-theme"
                    />
                </el-tooltip>
                <el-button v-if="auth" :icon="SwitchButton" @click="logout">Logout</el-button>
            </div>
        </header>

        <main class="internals-main">
            <div v-if="!auth" class="internals-login">
                <el-card shadow="never">
                    <h2>PokerTH Internals</h2>
                    <p class="admin-muted">Please sign in with your PokerTH admin account.</p>
                    <el-form @submit.prevent="login">
                        <el-form-item>
                            <el-input v-model="username" placeholder="Username" :prefix-icon="User" clearable />
                        </el-form-item>
                        <el-form-item>
                            <el-input
                                v-model="password"
                                type="password"
                                placeholder="Password"
                                :prefix-icon="Lock"
                                show-password
                                @keyup.enter="login"
                            />
                        </el-form-item>
                        <el-button type="primary" :loading="busy" class="internals-login__submit" @click="login">
                            Login
                        </el-button>
                    </el-form>
                </el-card>
            </div>

            <template v-else>
                <offenders-table
                    v-if="view === 'offenders'"
                    :key="'offenders-' + revision"
                    @show-reports="showReports"
                    @changed="revision++"
                />
                <reports-table
                    v-else-if="view === 'reports-gamename'"
                    :key="'gamename-' + revision"
                    type="gamename"
                    :preset="preset"
                    @changed="revision++"
                />
                <reports-table
                    v-else-if="view === 'reports-avatar'"
                    :key="'avatar-' + revision"
                    type="avatar"
                    :preset="preset"
                    @changed="revision++"
                />
                <ban-list v-else-if="view === 'banlist'" :key="'banlist-' + revision" @changed="revision++" />
                <adverts v-else-if="view === 'adverts'" />
                <server-log v-else-if="view === 'serverlog'" />
                <web-server-log v-else-if="view === 'webserverlog'" />
            </template>
        </main>
    </div>
</template>

<script>
import { Lock, Moon, Sunny, SwitchButton, User } from '@element-plus/icons-vue'
import Adverts from './Adverts.vue'
import BanList from './BanList.vue'
import OffendersTable from './OffendersTable.vue'
import ReportsTable from './ReportsTable.vue'
import ServerLog from './ServerLog.vue'
import WebServerLog from './WebServerLog.vue'
import { apiGet, apiPost, notice, reportError } from '../admin/adminUtils.js'
import { applyTheme, preferredTheme } from '../admin/theme.js'

export default {
    name: 'InternalsComponent',
    components: { Adverts, BanList, OffendersTable, ReportsTable, ServerLog, WebServerLog },
    props: ['authenticated'],
    data() {
        return {
            auth: false,
            busy: false,
            username: null,
            password: null,
            view: 'offenders',
            preset: '',
            revision: 0,
            theme: preferredTheme(),
            Lock,
            Moon,
            Sunny,
            SwitchButton,
            User,
        }
    },
    computed: {
        darkMode: {
            get() {
                return this.theme === 'dark'
            },
            set(value) {
                this.theme = applyTheme(value ? 'dark' : 'light')
            },
        },
    },
    mounted() {
        this.auth = this.authenticated === true || this.authenticated === 'true'
        applyTheme(this.theme)
    },
    methods: {
        navigate(index) {
            if (index === this.view) return
            this.preset = ''
            this.view = index
        },
        /** Sprung aus der Offender-Liste in die passende Report-Liste. */
        showReports(row) {
            this.preset = row.username || String(row.player_id)
            this.view = row.avatar_reports > row.gamename_reports ? 'reports-avatar' : 'reports-gamename'
        },
        async login() {
            if (!this.username || !this.password || this.password.length < 1 || this.username.length < 3) {
                return notice('Username and/or password too short.', 'error')
            }
            this.busy = true
            try {
                const data = await apiPost('/login', { username: this.username, password: this.password })
                if (data.success) {
                    this.auth = true
                    this.password = null
                    notice(data.msg)
                } else {
                    notice(data.msg || 'Login failed.', 'error')
                }
            } catch (err) {
                reportError(err, 'Login failed.')
            } finally {
                this.busy = false
            }
        },
        async logout() {
            try {
                const data = await apiGet('/logout')
                if (data.success) {
                    this.auth = false
                    notice(data.msg, 'info')
                } else {
                    notice(data.msg || 'Logout failed.', 'error')
                }
            } catch (err) {
                reportError(err, 'Logout failed.')
            }
        },
    },
}
</script>
