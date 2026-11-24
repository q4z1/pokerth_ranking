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
                <el-menu-item index="players">Players</el-menu-item>
                <el-sub-menu index="reports">
                    <template #title>
                        <el-badge :value="unseenTotal" :hidden="!unseenTotal" :max="99" class="internals-badge">
                            Reports
                        </el-badge>
                    </template>
                    <el-menu-item index="offenders">Repeat offenders</el-menu-item>
                    <el-menu-item index="collusion">Collusion</el-menu-item>
                    <el-menu-item index="reports-gamename">
                        <el-badge :value="unseen.gamename.count" :hidden="!unseen.gamename.count" :max="99" class="internals-badge">
                            Table names
                        </el-badge>
                    </el-menu-item>
                    <el-menu-item index="reports-avatar">
                        <el-badge :value="unseen.avatar.count" :hidden="!unseen.avatar.count" :max="99" class="internals-badge">
                            Avatars
                        </el-badge>
                    </el-menu-item>
                </el-sub-menu>
                <el-sub-menu index="moderation">
                    <template #title>Banlist</template>
                    <el-menu-item index="banlist">Banned players</el-menu-item>
                    <el-menu-item index="shadowmute">Shadow mutes</el-menu-item>
                    <el-menu-item index="audit">Audit log</el-menu-item>
                </el-sub-menu>
                <el-menu-item index="adverts">Adverts</el-menu-item>
                <el-sub-menu index="logs">
                    <template #title>
                        <el-badge
                            :value="alerts.unseen"
                            :hidden="!alerts.unseen"
                            :max="99"
                            :type="alerts.unseen_critical ? 'danger' : 'warning'"
                            class="internals-badge"
                        >
                            Logs
                        </el-badge>
                    </template>
                    <el-menu-item index="gameserveralerts">
                        <el-badge
                            :value="alerts.unseen"
                            :hidden="!alerts.unseen"
                            :max="99"
                            :type="alerts.unseen_critical ? 'danger' : 'warning'"
                            class="internals-badge"
                        >
                            Game-Server Alerts
                        </el-badge>
                    </el-menu-item>
                    <el-menu-item index="serverlog">Game-Server Log</el-menu-item>
                    <el-menu-item index="servermessages">Game-Server Messages</el-menu-item>
                    <el-menu-item index="webserverlog">Webserver Log</el-menu-item>
                </el-sub-menu>
                <el-menu-item index="lobbychat">Lobby Chat</el-menu-item>
            </el-menu>
            <div v-else class="internals-nav internals-nav--empty"></div>

            <div class="internals-actions">
                <!-- Livewerte des Game-Servers (Heartbeat) auf jeder Admin-Seite. -->
                <pokerth-now-component v-if="auth" variant="header" />
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
                <player-admin v-if="view === 'players'" @show-reports="showReports" />
                <offenders-table
                    v-else-if="view === 'offenders'"
                    :key="'offenders-' + revision"
                    @show-reports="showReports"
                    @changed="revision++"
                />
                <collusion-table v-else-if="view === 'collusion'" :key="'collusion-' + revision" />
                <reports-table
                    v-else-if="view === 'reports-gamename'"
                    :key="'gamename-' + revision"
                    type="gamename"
                    :preset="preset"
                    @changed="onReportsChanged('gamename')"
                />
                <reports-table
                    v-else-if="view === 'reports-avatar'"
                    :key="'avatar-' + revision"
                    type="avatar"
                    :preset="preset"
                    @changed="onReportsChanged('avatar')"
                />
                <ban-list v-else-if="view === 'banlist'" :key="'banlist-' + revision" @changed="revision++" />
                <shadow-mute-list v-else-if="view === 'shadowmute'" />
                <audit-log v-else-if="view === 'audit'" />
                <adverts v-else-if="view === 'adverts'" />
                <game-server-alerts v-else-if="view === 'gameserveralerts'" :seen-id="alertsSeenOnOpen" />
                <server-log v-else-if="view === 'serverlog'" />
                <server-messages v-else-if="view === 'servermessages'" />
                <web-server-log v-else-if="view === 'webserverlog'" />
                <lobby-chat v-else-if="view === 'lobbychat'" />
            </template>
        </main>
    </div>
</template>

<script>
import { Lock, Moon, Sunny, SwitchButton, User } from '@element-plus/icons-vue'
import Adverts from './Adverts.vue'
import AuditLog from './AuditLog.vue'
import BanList from './BanList.vue'
import CollusionTable from './CollusionTable.vue'
import GameServerAlerts from './GameServerAlerts.vue'
import LobbyChat from './LobbyChat.vue'
import OffendersTable from './OffendersTable.vue'
import PlayerAdmin from './PlayerAdmin.vue'
import PokerthNowComponent from './PokerthNowComponent.vue'
import ReportsTable from './ReportsTable.vue'
import ServerLog from './ServerLog.vue'
import ServerMessages from './ServerMessages.vue'
import ShadowMuteList from './ShadowMuteList.vue'
import WebServerLog from './WebServerLog.vue'
import { apiGet, apiPost, notice, reportError } from '../admin/adminUtils.js'
import { applyTheme, preferredTheme } from '../admin/theme.js'

const UNSEEN_POLL_MS = 60000
const SEEN_AFTER_MS = 10000

export default {
    name: 'InternalsComponent',
    components: { Adverts, AuditLog, BanList, CollusionTable, GameServerAlerts, LobbyChat, OffendersTable, PlayerAdmin, PokerthNowComponent, ReportsTable, ServerLog, ServerMessages, ShadowMuteList, WebServerLog },
    props: ['authenticated'],
    data() {
        return {
            auth: false,
            busy: false,
            username: null,
            password: null,
            view: 'lobbychat',
            preset: '',
            revision: 0,
            theme: preferredTheme(),
            unseen: { gamename: { count: 0, max_id: 0 }, avatar: { count: 0, max_id: 0 } },
            alerts: { unseen: 0, unseen_critical: 0, max_id: 0, seen_id: 0 },
            alertsSeenOnOpen: 0,
            unseenTimer: null,
            seenTimer: null,
            Lock,
            Moon,
            Sunny,
            SwitchButton,
            User,
        }
    },
    computed: {
        unseenTotal() {
            return this.unseen.gamename.count + this.unseen.avatar.count
        },
        /** Report-Typ der aktuellen Seite, sonst null. */
        reportType() {
            return { 'reports-gamename': 'gamename', 'reports-avatar': 'avatar' }[this.view] ?? null
        },
        darkMode: {
            get() {
                return this.theme === 'dark'
            },
            set(value) {
                this.theme = applyTheme(value ? 'dark' : 'light')
            },
        },
    },
    watch: {
        auth: {
            immediate: true,
            handler(value) {
                clearInterval(this.unseenTimer)
                this.unseenTimer = null
                if (!value) return
                this.loadUnseen()
                this.unseenTimer = setInterval(() => {
                    if (!document.hidden) this.loadUnseen()
                }, UNSEEN_POLL_MS)
            },
        },
        /** 10 s auf einer Report-Seite gelten als "gesehen". */
        reportType: {
            immediate: true,
            handler(type) {
                clearTimeout(this.seenTimer)
                this.seenTimer = null
                if (type) this.seenTimer = setTimeout(() => this.markSeen(type), SEEN_AFTER_MS)
            },
        },
        /** Alerts-Seite: wie bei den Reports gelten 10 s dort als "gesehen". */
        view(value) {
            clearTimeout(this.alertsSeenTimer)
            this.alertsSeenTimer = null
            if (value !== 'gameserveralerts') return
            this.alertsSeenOnOpen = this.alerts.seen_id
            this.alertsSeenTimer = setTimeout(() => this.markAlertsSeen(), SEEN_AFTER_MS)
        },
    },
    mounted() {
        this.auth = this.authenticated === true || this.authenticated === 'true'
        applyTheme(this.theme)
        document.addEventListener('visibilitychange', this.onVisible)
        // 401 = Session weg oder Admin-Rechte entzogen (EnsureAdmin) -> zurück zum Login.
        this.authInterceptor = window.axios.interceptors.response.use(null, (err) => {
            if (err?.response?.status === 401 && this.auth && !String(err.config?.url || '').endsWith('/login')) {
                this.auth = false
                notice('Your session has ended – please log in again.', 'warning')
            }
            return Promise.reject(err)
        })
    },
    beforeUnmount() {
        clearInterval(this.unseenTimer)
        clearTimeout(this.seenTimer)
        clearTimeout(this.alertsSeenTimer)
        document.removeEventListener('visibilitychange', this.onVisible)
        if (this.authInterceptor !== undefined) window.axios.interceptors.response.eject(this.authInterceptor)
    },
    methods: {
        onVisible() {
            if (!document.hidden && this.auth) this.loadUnseen()
        },
        async loadUnseen() {
            try {
                const data = await apiGet('/reports/unseen')
                if (data.success) this.unseen = data.types
            } catch {
                // Badge ist Komfort – Fehler hier nicht aufdrängen.
            }
            try {
                const data = await apiGet('/gameserver/alerts/unseen')
                if (data.success) this.alerts = data
            } catch {
                // siehe oben
            }
        },
        async markAlertsSeen() {
            if (!this.alerts.unseen) return
            try {
                const data = await apiPost('/gameserver/alerts/seen', { upto: this.alerts.max_id })
                if (data.success) this.alerts = data
            } catch {
                // siehe loadUnseen
            }
        },
        async markSeen(type) {
            if (!this.unseen[type]?.count) return
            try {
                const data = await apiPost('/reports/seen', { type, upto: this.unseen[type].max_id })
                if (data.success) this.unseen = data.types
            } catch {
                // siehe loadUnseen
            }
        },
        /** Aktion auf einer Report-Seite (Ban, Ignore, …) gilt ebenfalls als "gesehen". */
        onReportsChanged(type) {
            this.revision++
            this.markSeen(type)
        },
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
