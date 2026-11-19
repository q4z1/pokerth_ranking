<template>
    <admin-panel
        :title="mode === 'online' ? 'Online now' : 'Players'"
        :subtitle="subtitle"
        :count="mode === 'online' ? filteredRows.length : total"
        :loading="loading"
        :total="mode === 'online' ? filteredRows.length : total"
        :page="page"
        :page-size="pageSize"
        @refresh="load"
        @update:page="onPage"
        @update:pageSize="onPageSize"
    >
        <template #toolbar>
            <el-radio-group v-model="mode" @change="onModeChange">
                <el-radio-button value="accounts">Accounts</el-radio-button>
                <el-radio-button value="online">Online now{{ onlineTotal !== null ? ` (${onlineTotal})` : '' }}</el-radio-button>
            </el-radio-group>
            <template v-if="mode === 'accounts'">
                <el-input
                    v-model="filters.q"
                    class="admin-toolbar__search"
                    placeholder="Nickname or ID"
                    :prefix-icon="Search"
                    clearable
                    @input="debouncedReload"
                />
                <el-input
                    v-model="filters.ip"
                    class="admin-toolbar__search player-admin__ip-input"
                    placeholder="IP, prefix or a.b.c.d/nn"
                    clearable
                    @input="debouncedReload"
                />
                <el-select v-model="filters.ip_scope" class="admin-toolbar__select player-admin__narrow" @change="reload">
                    <el-option label="Last IP" value="last" />
                    <el-option label="Any IP used" value="any" />
                </el-select>
                <el-select v-model="filters.status" class="admin-toolbar__select player-admin__narrow" @change="reload">
                    <el-option label="All" value="all" />
                    <el-option label="Not banned" value="active" />
                    <el-option label="Banned" value="banned" />
                </el-select>
                <el-checkbox v-model="filters.online" label="Online only" @change="reload" />
            </template>
            <el-input
                v-else
                v-model="search"
                class="admin-toolbar__search"
                placeholder="Filter nickname or IP"
                :prefix-icon="Search"
                clearable
            />
        </template>

        <!-- Accounts (serverseitig gefiltert/sortiert/geblättert) -->
        <el-table
            v-if="mode === 'accounts'"
            v-loading="loading"
            class="admin-table"
            :data="rows"
            row-key="player_id"
            stripe
            :row-class-name="rowClass"
            :empty-text="loading ? 'Loading…' : 'No players found.'"
            :default-sort="{ prop: sort, order: dir === 'asc' ? 'ascending' : 'descending' }"
            @sort-change="onServerSort"
        >
            <el-table-column prop="online" label="" width="44" align="center" sortable="custom">
                <template #default="{ row }">
                    <el-tooltip v-if="row.online" :content="'Online since ' + formatDateTime(row.online_since)" placement="top">
                        <span class="player-admin__dot is-online" />
                    </el-tooltip>
                    <span v-else class="player-admin__dot" />
                </template>
            </el-table-column>
            <el-table-column prop="player_id" label="ID" width="90" sortable="custom" />
            <el-table-column prop="username" label="Nickname" min-width="170" sortable="custom">
                <template #default="{ row }">
                    <el-link type="primary" :underline="false" @click="openDetail(row)">{{ row.username }}</el-link>
                    <el-tag v-if="row.banned" size="small" type="danger" disable-transitions>banned</el-tag>
                    <el-tag v-if="row.muted" size="small" type="warning" effect="plain" disable-transitions>muted</el-tag>
                </template>
            </el-table-column>
            <el-table-column prop="last_login" label="Last login" width="150" sortable="custom">
                <template #default="{ row }">{{ formatDateTime(row.last_login) }}</template>
            </el-table-column>
            <el-table-column prop="last_ip" label="Last IP" min-width="150" sortable="custom">
                <template #default="{ row }">
                    <ip-cell :ip="row.online ? row.online_ip || row.last_ip : row.last_ip" @filter="filterByIp" />
                </template>
            </el-table-column>
            <el-table-column label="Location" min-width="200">
                <template #default="{ row }">
                    <geo-cell :geo="row.online ? row.online_geo || row.geo : row.geo" />
                </template>
            </el-table-column>
            <el-table-column label="IP shared" width="100" align="right">
                <template #default="{ row }">
                    <shared-cell
                        :count="row.ip_accounts"
                        :ip="row.online ? row.online_ip || row.last_ip : row.last_ip"
                        @show="showIpNicks"
                    />
                </template>
            </el-table-column>
            <el-table-column label="Reports" width="90" align="right">
                <template #default="{ row }">
                    <reports-cell
                        :total="row.total_reports"
                        :counts="row.reports"
                        :recent="row.recent_reports"
                        @show="showReports(row)"
                    />
                </template>
            </el-table-column>
            <el-table-column prop="created" label="Registered" width="110" sortable="custom">
                <template #default="{ row }">{{ formatDate(row.created) }}</template>
            </el-table-column>
            <el-table-column label="Action" width="290" align="right">
                <template #default="{ row }">
                    <el-tooltip :content="row.online ? 'Private message from the admin bot' : 'Player is not online'" placement="top">
                        <span>
                            <el-button size="small" :disabled="!row.online" @click="askMessage(row)">Message</el-button>
                        </span>
                    </el-tooltip>
                    <el-tooltip
                        :content="row.online ? 'Kick, 24 h IP ban and account ban – live on the game server' : 'Player is not online'"
                        placement="top"
                    >
                        <span>
                            <el-button size="small" type="danger" :disabled="!row.online" @click="askKickban(row)">Kickban</el-button>
                        </span>
                    </el-tooltip>
                    <el-button v-if="row.banned" size="small" type="success" plain @click="setBan(row, false)">Unban</el-button>
                    <el-button v-else size="small" type="danger" plain @click="setBan(row, true)">Ban</el-button>
                </template>
            </el-table-column>
        </el-table>

        <!-- Online now (inkl. Gäste) -->
        <el-table
            v-else
            v-loading="loading"
            class="admin-table"
            :data="pagedRows"
            row-key="session_id"
            stripe
            :empty-text="loading ? 'Loading…' : 'Nobody online.'"
            :default-sort="{ prop: 'online_since', order: 'descending' }"
            @sort-change="onSortChange"
        >
            <el-table-column prop="username" label="Nickname" min-width="170" sortable="custom">
                <template #default="{ row }">
                    <el-link v-if="row.player_id" type="primary" :underline="false" @click="openDetail(row)">
                        {{ row.username }}
                    </el-link>
                    <span v-else>{{ row.username }}</span>
                    <el-tag v-if="row.guest" size="small" type="info" disable-transitions>guest</el-tag>
                    <el-tag v-if="row.banned" size="small" type="danger" disable-transitions>banned</el-tag>
                </template>
            </el-table-column>
            <el-table-column prop="online_since" label="Online since" width="150" sortable="custom">
                <template #default="{ row }">{{ formatDateTime(row.online_since) }}</template>
            </el-table-column>
            <el-table-column prop="ip" label="IP" min-width="150" sortable="custom">
                <template #default="{ row }">
                    <ip-cell :ip="row.ip" @filter="filterByIp" />
                </template>
            </el-table-column>
            <el-table-column label="Location" min-width="200">
                <template #default="{ row }"><geo-cell :geo="row.geo" /></template>
            </el-table-column>
            <el-table-column prop="ip_accounts" label="IP shared" width="100" align="right" sortable="custom">
                <template #default="{ row }">
                    <!-- Gäste zählen nicht mit; eine 0 ohne Accounts auf der IP ist nur Rauschen. -->
                    <shared-cell v-if="!row.guest || row.ip_accounts" :count="row.ip_accounts" :ip="row.ip" @show="showIpNicks" />
                    <!-- Leerer Slot würde el-table auf den prop-Rohwert (0) zurückfallen lassen. -->
                    <span v-else></span>
                </template>
            </el-table-column>
            <el-table-column label="Client" width="170">
                <template #default="{ row }">{{ clientLabel(row.build_id, row.platform) }}</template>
            </el-table-column>
            <el-table-column prop="table" label="Table" min-width="170" sortable="custom">
                <template #default="{ row }">
                    <span v-if="row.table" class="player-admin__table" :title="row.table_title">
                        <span class="player-admin__table-state" :class="{ 'is-running': row.table_running }" />
                        <span v-if="row.table_spectator" title="spectating">👁 </span>{{ row.table }}
                    </span>
                    <span v-else-if="row.table_known" class="admin-muted">lobby</span>
                    <span v-else class="admin-muted" :title="lobbyError || 'Loading the lobby from the game server …'">{{ lobbyError ? '?' : '…' }}</span>
                </template>
            </el-table-column>
            <el-table-column label="Action" width="190" align="right">
                <template #default="{ row }">
                    <el-button size="small" @click="askMessage(row)">Message</el-button>
                    <el-button size="small" type="danger" @click="askKickban(row)">Kickban</el-button>
                </template>
            </el-table-column>
        </el-table>

        <p class="player-admin__note admin-muted">
            Online = open session in the current game-server run, refreshed every 30 s. Times are server time (Europe/Berlin).
            “IP shared” = distinct registered nicks on that IP in the last 14 days (guests not counted).
            <template v-if="geoip">
                <a href="https://db-ip.com" target="_blank" rel="noopener">IP Geolocation by DB-IP</a>.
            </template>
            <template v-else>GeoIP database missing – run <code>php artisan geoip:update</code>.</template>
        </p>

        <!-- Private Nachricht (Einweg, Antworten werden nicht gelesen) -->
        <el-dialog v-model="pm.open" title="Private message" width="min(520px, 94vw)" append-to-body>
            <template v-if="pm.row">
                <p>
                    To <strong>{{ pm.row.username }}</strong>
                    <span v-if="pm.row.guest" class="admin-muted"> (guest)</span>,
                    sent as a private chat message from the admin bot.
                </p>
                <el-input
                    v-model="pm.text"
                    type="textarea"
                    :autosize="{ minRows: 2, maxRows: 5 }"
                    placeholder="Message"
                    @keydown.enter.exact.prevent="doMessage"
                />
                <div class="player-admin__pm-meta admin-muted">
                    <span :class="{ 'player-admin__pm-over': pmBytes > PM_MAX_BYTES }">{{ pmBytes }} / {{ PM_MAX_BYTES }} bytes</span>
                    <span>Replies go to the bot account and are not read.</span>
                </div>
                <p class="admin-muted player-admin__pm-hint">
                    A player at a running table gets the message after the game (servers before that feature reject it).
                </p>
            </template>
            <template #footer>
                <el-button @click="pm.open = false">Cancel</el-button>
                <el-button
                    type="primary"
                    :loading="pm.busy"
                    :disabled="!pm.text.trim() || pmBytes > PM_MAX_BYTES"
                    @click="doMessage"
                >Send</el-button>
            </template>
        </el-dialog>

        <!-- Kickban-Bestätigung -->
        <el-dialog v-model="kick.open" title="Kickban player" width="min(520px, 94vw)" append-to-body>
            <template v-if="kick.row">
                <p>
                    <strong>{{ kick.row.username }}</strong>
                    <span v-if="kick.row.guest" class="admin-muted"> (guest)</span>
                    will be kicked from the game server right now. The server also bans the IP for 24 h
                    <template v-if="!kick.row.guest">and sets the account to banned</template>.
                </p>
                <p>
                    IP <code>{{ kickIp || '—' }}</code>
                    <geo-cell :geo="kickGeo" class="player-admin__inline-geo" />
                </p>
                <el-alert
                    v-if="kick.row.ip_accounts > 1"
                    type="warning"
                    :closable="false"
                    show-icon
                    :title="`${kick.row.ip_accounts} different registered nicks used this IP in the last 14 days – the 24 h IP ban hits all of them (shared IP / CGNAT / VPN?).`"
                >
                    <el-link type="primary" :underline="false" @click="showIpNicks(kickIp)">Show the nicks</el-link>
                </el-alert>
                <el-checkbox v-if="kick.row.player_id" v-model="kick.resolveReports" label="Close open reports against this player" />
            </template>
            <template #footer>
                <el-button @click="kick.open = false">Cancel</el-button>
                <el-button type="danger" :loading="kick.busy" @click="doKickban">Kickban now</el-button>
            </template>
        </el-dialog>

        <!-- Nicks auf einer IP -->
        <el-dialog v-model="ipNicks.open" width="min(760px, 96vw)" append-to-body>
            <template #header>
                Nicks on <code>{{ ipNicks.ip }}</code>
                <geo-cell :geo="ipNicks.geo" class="player-admin__inline-geo" />
            </template>
            <el-table
                v-loading="ipNicks.loading"
                :data="ipNicks.list"
                size="small"
                class="admin-table"
                max-height="60vh"
                :row-class-name="({ row }) => (row.banned ? 'is-banned' : row.recent ? '' : 'player-admin__old')"
                empty-text="No sessions recorded for this IP."
            >
                <el-table-column label="Nickname" min-width="170">
                    <template #default="{ row }">
                        <span v-if="row.online" class="player-admin__dot is-online" title="online" />
                        <el-link v-if="row.player_id" type="primary" :underline="false" @click="openDetail(row)">{{ row.nick }}</el-link>
                        <span v-else>{{ row.nick }}</span>
                        <el-tag v-if="row.banned" size="small" type="danger" disable-transitions>banned</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="sessions" label="Sessions" width="85" align="right">
                    <template #default="{ row }">
                        <span v-if="row.sessions">{{ row.sessions }}</span>
                        <el-tooltip v-else content="Only player.last_ip matches, no recorded session" placement="top">
                            <span class="admin-muted">last IP</span>
                        </el-tooltip>
                    </template>
                </el-table-column>
                <el-table-column label="First seen" width="140">
                    <template #default="{ row }">{{ formatDateTime(row.first_seen) }}</template>
                </el-table-column>
                <el-table-column label="Last seen" width="140">
                    <template #default="{ row }">{{ formatDateTime(row.last_seen) }}</template>
                </el-table-column>
            </el-table>
            <p class="player-admin__note admin-muted">
                Greyed out = not seen on this IP in the last 14 days (not part of the “IP shared” count).
                Sessions are recorded since 2026-08-05.
            </p>
        </el-dialog>

        <!-- Details -->
        <el-drawer v-model="detail.open" :title="detail.title || 'Player'" size="min(820px, 100vw)" append-to-body>
            <div
                v-loading="detail.loading"
                element-loading-text="Loading player…"
                class="player-admin__detail"
                :class="{ 'is-loading': detail.loading }"
            >
                <template v-if="detail.data">
                    <div class="player-admin__overview">
                    <div class="player-admin__avatar">
                        <el-image
                            v-if="detail.data.player.avatar_hash"
                            :src="avatarUrl(detail.data.player.avatar_hash)"
                            :preview-src-list="[avatarUrl(detail.data.player.avatar_hash)]"
                            preview-teleported
                            hide-on-click-modal
                            fit="cover"
                        >
                            <template #error><span class="admin-muted">no file</span></template>
                        </el-image>
                        <span v-else class="admin-muted">no avatar</span>
                        <el-tag
                            v-if="detail.data.player.avatar_blacklisted"
                            size="small"
                            type="danger"
                            disable-transitions
                        >blacklisted</el-tag>
                    </div>
                    <el-descriptions :column="2" border size="small">
                        <el-descriptions-item label="ID">{{ detail.data.player.player_id }}</el-descriptions-item>
                        <el-descriptions-item label="Status">
                            <el-tag v-if="detail.data.player.online" size="small" type="success" disable-transitions>
                                online since {{ formatDateTime(detail.data.player.online_since) }}
                            </el-tag>
                            <el-tag v-else size="small" type="info" disable-transitions>offline</el-tag>
                            <el-tag v-if="detail.data.player.banned" size="small" type="danger" disable-transitions>banned</el-tag>
                            <el-tag v-if="detail.data.player.muted" size="small" type="warning" disable-transitions>muted</el-tag>
                        </el-descriptions-item>
                        <el-descriptions-item label="Registered">{{ formatDateTime(detail.data.player.created) }}</el-descriptions-item>
                        <el-descriptions-item label="Last login">{{ formatDateTime(detail.data.player.last_login) }}</el-descriptions-item>
                        <el-descriptions-item label="Last IP">
                            <ip-cell :ip="detail.data.player.last_ip" @filter="filterByIp" />
                        </el-descriptions-item>
                        <el-descriptions-item label="Location"><geo-cell :geo="detail.data.player.geo" /></el-descriptions-item>
                        <el-descriptions-item label="Reports">
                            <reports-cell
                                :total="detail.data.player.reports.total"
                                :counts="detail.data.player.reports"
                                :recent="detail.data.player.recent_reports"
                                @show="showReports(detail.data.player)"
                            />
                            <span v-if="detail.data.player.reports.total" class="admin-muted">
                                ({{ detail.data.player.reports.open }} open)
                            </span>
                        </el-descriptions-item>
                        <el-descriptions-item label="Action">
                            <el-button
                                size="small"
                                type="danger"
                                :disabled="!detail.data.player.online"
                                @click="askKickban(detailAsRow)"
                            >Kickban</el-button>
                            <el-button v-if="detail.data.player.banned" size="small" type="success" plain @click="setBan(detailAsRow, false)">Unban</el-button>
                            <el-button v-else size="small" type="danger" plain @click="setBan(detailAsRow, true)">Ban</el-button>
                        </el-descriptions-item>
                    </el-descriptions>
                    </div>

                    <h4>Other accounts on these IPs</h4>
                    <el-table :data="detail.data.related" size="small" class="admin-table" empty-text="None.">
                        <el-table-column label="Nickname" min-width="150">
                            <template #default="{ row }">
                                <el-link type="primary" :underline="false" @click="openDetail(row)">{{ row.username }}</el-link>
                                <el-tag v-if="row.banned" size="small" type="danger" disable-transitions>banned</el-tag>
                            </template>
                        </el-table-column>
                        <el-table-column prop="ips" label="Shared IP(s)" min-width="180" />
                        <el-table-column label="Last login" width="140">
                            <template #default="{ row }">{{ formatDateTime(row.last_login) }}</template>
                        </el-table-column>
                    </el-table>

                    <h4>IP addresses <span class="admin-muted">(since 2026-08-05)</span></h4>
                    <el-table :data="detail.data.ips" size="small" class="admin-table" empty-text="No sessions recorded.">
                        <el-table-column label="IP" min-width="140">
                            <template #default="{ row }"><ip-cell :ip="row.ip" @filter="filterByIp" /></template>
                        </el-table-column>
                        <el-table-column label="Location" min-width="180">
                            <template #default="{ row }"><geo-cell :geo="row.geo" /></template>
                        </el-table-column>
                        <el-table-column prop="sessions" label="Sessions" width="85" align="right" />
                        <el-table-column label="Shared" width="75" align="right">
                            <template #default="{ row }"><shared-cell :count="row.accounts_14d" :ip="row.ip" @show="showIpNicks" /></template>
                        </el-table-column>
                        <el-table-column label="Last seen" width="140">
                            <template #default="{ row }">{{ formatDateTime(row.last_seen) }}</template>
                        </el-table-column>
                    </el-table>

                    <h4>Last sessions</h4>
                    <el-table :data="detail.data.sessions" size="small" class="admin-table" empty-text="No sessions recorded.">
                        <el-table-column label="Connected" width="140">
                            <template #default="{ row }">{{ formatDateTime(row.connected_at) }}</template>
                        </el-table-column>
                        <el-table-column label="Duration" width="90" align="right">
                            <template #default="{ row }">
                                <span v-if="row.disconnected_at === null" class="player-admin__live">live</span>
                                <span v-else>{{ formatDuration(row.duration_s) }}</span>
                            </template>
                        </el-table-column>
                        <el-table-column label="IP" min-width="130">
                            <template #default="{ row }"><ip-cell :ip="row.ip" @filter="filterByIp" /></template>
                        </el-table-column>
                        <el-table-column label="Location" min-width="160">
                            <template #default="{ row }"><geo-cell :geo="row.geo" short /></template>
                        </el-table-column>
                        <el-table-column label="Client" min-width="150">
                            <template #default="{ row }">{{ clientLabel(row.build_id, row.platform) }}</template>
                        </el-table-column>
                    </el-table>
                </template>
            </div>
        </el-drawer>
    </admin-panel>
</template>

<script>
import { h, resolveComponent } from 'vue'
import { Search } from '@element-plus/icons-vue'
import AdminPanel from './AdminPanel.vue'
import {
    API_BASE,
    apiGet,
    apiPost,
    confirmAction,
    formatDate,
    formatDateTime,
    listMixin,
    notice,
    reportError,
    stateMeta,
} from '../admin/adminUtils.js'

const PLATFORMS = ['', 'Windows', 'Linux', 'macOS', 'Android', 'iOS']
const CLIENT_TYPES = { 1: 'Qt Widget', 2: 'QML', 3: 'Web' }

function flag(cc) {
    if (!cc || !/^[A-Z]{2}$/.test(cc)) return ''
    return String.fromCodePoint(...[...cc].map((c) => 0x1f1e6 + c.charCodeAt(0) - 65))
}

/** IP in Monospace; Klick filtert die Spielerliste nach genau dieser IP. */
const IpCell = {
    props: { ip: { type: String, default: null } },
    emits: ['filter'],
    setup(props, { emit }) {
        return () =>
            props.ip
                ? h(
                      'a',
                      {
                          class: 'player-admin__ip',
                          title: 'Show all accounts that used this IP',
                          onClick: () => emit('filter', props.ip),
                      },
                      props.ip
                  )
                : h('span', { class: 'admin-muted' }, '—')
    },
}

const GeoCell = {
    props: { geo: { type: Object, default: null }, short: { type: Boolean, default: false } },
    setup(props) {
        return () => {
            const g = props.geo
            if (!g || !g.country) return h('span', { class: 'admin-muted' }, '—')
            const place = props.short
                ? [g.city, g.country].filter(Boolean).join(', ')
                : [g.city, g.region, g.country_name].filter(Boolean).filter((v, i, a) => a.indexOf(v) === i).join(', ')
            return h('span', { class: 'player-admin__geo', title: [g.city, g.region, g.country_name].filter(Boolean).join(', ') }, [
                h('span', { class: 'player-admin__flag' }, flag(g.country)),
                ' ',
                place,
            ])
        }
    },
}

/** Anzahl Nicks auf einer IP (14 Tage); Klick öffnet die Liste der Nicks. */
const SharedCell = {
    props: { count: { type: Number, default: 0 }, ip: { type: String, default: null } },
    emits: ['show'],
    setup(props, { emit }) {
        return () => {
            if (!props.ip) return h('span', { class: 'admin-muted' }, '—')
            const n = props.count || 0
            const type = n >= 10 ? 'danger' : n > 1 ? 'warning' : 'info'
            return h(
                resolveComponent('ElTooltip'),
                { content: `${n} different nick(s) on this IP in the last 14 days – click to list them`, placement: 'top' },
                () =>
                    h(
                        'button',
                        {
                            type: 'button',
                            class: `player-admin__shared el-tag el-tag--${type} el-tag--small el-tag--plain`,
                            onClick: () => emit('show', props.ip),
                        },
                        String(n || '0')
                    )
            )
        }
    },
}

/**
 * Anzahl Meldungen gegen den Spieler; Tooltip zeigt die letzten Reports,
 * Klick springt in die passende Report-Liste (vorgefiltert auf den Spieler).
 */
const ReportsCell = {
    props: {
        total: { type: Number, default: 0 },
        counts: { type: Object, default: null },
        recent: { type: Array, default: () => [] },
    },
    emits: ['show'],
    setup(props, { emit }) {
        const line = (r) => {
            const meta = stateMeta(r.state)
            const what =
                r.type === 'avatar'
                    ? [
                          h('img', {
                              class: 'player-admin__report-avatar',
                              src: `${API_BASE}/reports/avatar/image/${r.avatar_hash}`,
                              alt: '',
                              loading: 'lazy',
                          }),
                          h('span', 'Avatar'),
                      ]
                    : [h('span', { class: 'player-admin__report-label' }, 'Table:'), h('span', { class: 'player-admin__report-name' }, r.game_name || '—')]
            return h('li', { class: 'player-admin__report' }, [
                h('span', { class: 'player-admin__report-date' }, formatDate(r.timestamp)),
                h(resolveComponent('ElTag'), { size: 'small', type: meta.type, disableTransitions: true }, () => meta.label),
                ...what,
            ])
        }
        return () => {
            if (!props.total) return h('span', { class: 'admin-muted' }, '—')
            const c = props.counts || {}
            const parts = []
            if (c.gamename) parts.push(`${c.gamename} table name`)
            if (c.avatar) parts.push(`${c.avatar} avatar`)
            const more = props.total - props.recent.length
            return h(
                resolveComponent('ElTooltip'),
                { placement: 'left', effect: 'light', showAfter: 150 },
                {
                    content: () =>
                        h('div', { class: 'player-admin__reports-tip' }, [
                            h('div', { class: 'player-admin__reports-head' }, [
                                h('strong', `${props.total} report(s)`),
                                parts.length ? ` – ${parts.join(', ')}` : '',
                                c.open ? `, ${c.open} open` : '',
                            ]),
                            h('ul', props.recent.map(line)),
                            more > 0 ? h('div', { class: 'admin-muted' }, `… and ${more} older`) : null,
                            h('div', { class: 'admin-muted player-admin__reports-hint' }, 'Click to open the report list'),
                        ]),
                    default: () =>
                        h(
                            'button',
                            {
                                type: 'button',
                                class: 'player-admin__shared el-tag el-tag--warning el-tag--small el-tag--plain',
                                onClick: () => emit('show'),
                            },
                            String(props.total)
                        ),
                }
            )
        }
    },
}

const ONLINE_REFRESH_MS = 30000
/** ValidateChatRequestMessage: 1–128 Byte. */
const PM_MAX_BYTES = 128

export default {
    name: 'PlayerAdmin',
    components: { AdminPanel, IpCell, GeoCell, SharedCell, ReportsCell },
    mixins: [listMixin],
    emits: ['show-reports'],
    data() {
        return {
            // Startansicht: wer gerade online ist, ist im Alltag die häufigste Frage.
            mode: 'online',
            // Mixin-Defaults für die Online-Ansicht (wie in onModeChange).
            sortProp: 'online_since',
            sortOrder: 'descending',
            refreshTimer: null,
            filters: { q: '', ip: '', ip_scope: 'last', status: 'all', online: false },
            sort: 'last_login',
            dir: 'desc',
            total: 0,
            pageSize: 50,
            onlineTotal: null,
            geoip: true,
            requestNo: 0,
            debounceTimer: null,
            kick: { open: false, busy: false, row: null, resolveReports: true },
            pm: { open: false, busy: false, row: null, text: '' },
            // Lobby-Snapshot vom Game-Server (Bot-Login, serverseitig 60 s
            // gecacht): server_session.last_game_id wird erst beim Disconnect
            // geschrieben, die aktuelle Tischbelegung kennt nur die Lobby.
            lobbySeats: null,
            lobbyError: null,
            PM_MAX_BYTES,
            detail: { open: false, loading: false, data: null, title: '', requestId: 0 },
            ipNicks: { open: false, loading: false, ip: null, geo: null, list: [] },
            Search,
        }
    },
    computed: {
        subtitle() {
            if (this.mode === 'online') return 'incl. guests'
            const parts = []
            if (this.filters.ip) parts.push(`IP ${this.filters.ip_scope === 'any' ? 'ever used' : 'last'}: ${this.filters.ip}`)
            if (this.filters.online) parts.push('online only')
            return parts.join(' · ')
        },
        /** Online-Zeilen um den aktuellen Tisch aus dem Lobby-Snapshot ergänzt. */
        baseRows() {
            if (this.mode !== 'online') return this.rows
            const seats = this.lobbySeats
            return this.rows.map((row) => {
                const seat = seats ? seats.get((row.username || '').toLowerCase()) : undefined
                return {
                    ...row,
                    table: seat?.table ?? null,
                    table_running: !!seat?.running,
                    table_spectator: !!seat?.spectator,
                    table_title: seat?.table ? `${seat.running ? 'Running' : 'Open'} table${seat.spectator ? ', spectating' : ''}` : null,
                    // Nur wenn der Snapshot den Spieler kennt, ist "lobby" eine Aussage.
                    table_known: !!seat,
                }
            })
        },
        pmBytes() {
            return new TextEncoder().encode(this.pm.text.trim()).length
        },
        kickIp() {
            const r = this.kick.row
            return r ? r.ip || r.online_ip || r.last_ip : null
        },
        kickGeo() {
            const r = this.kick.row
            return r ? r.online_geo || r.geo || null : null
        },
        detailAsRow() {
            const p = this.detail.data?.player
            if (!p) return null
            return { ...p, ip_accounts: p.ip_accounts }
        },
    },
    mounted() {
        this.load()
        // Online-Tabelle aktuell halten; nur sichtbar und nur in der Online-Ansicht.
        this.refreshTimer = setInterval(() => {
            if (this.mode === 'online' && !document.hidden && !this.loading) this.loadOnline(true)
        }, ONLINE_REFRESH_MS)
        document.addEventListener('visibilitychange', this.onVisible)
    },
    beforeUnmount() {
        clearTimeout(this.debounceTimer)
        clearInterval(this.refreshTimer)
        document.removeEventListener('visibilitychange', this.onVisible)
    },
    methods: {
        /** Admin-Route liefert auch gesperrte (quarantänierte) Avatare aus. */
        avatarUrl(hash) {
            return `${API_BASE}/reports/avatar/image/${hash}`
        },
        /** Sprung in die Report-Liste, vorgefiltert auf diesen Spieler. */
        showReports(player) {
            const c = player.reports || {}
            this.detail.open = false
            this.$emit('show-reports', {
                player_id: player.player_id,
                username: player.username,
                gamename_reports: c.gamename || 0,
                avatar_reports: c.avatar || 0,
            })
        },
        formatDate,
        formatDateTime,
        matches(row, term) {
            return (row.username || '').toLowerCase().includes(term) || (row.ip || '').startsWith(term)
        },
        rowClass({ row }) {
            return row.banned ? 'is-banned' : ''
        },
        clientLabel(buildId, platform) {
            if (!buildId) return '—'
            const type = CLIENT_TYPES[(buildId >>> 24) & 0xff] || 'Client'
            const ver = [(buildId >>> 16) & 0xff, (buildId >>> 8) & 0xff, buildId & 0xff].join('.')
            const os = PLATFORMS[platform] || ''
            return `${type} ${ver}${os ? ' · ' + os : ''}`
        },
        formatDuration(s) {
            if (s === null || s === undefined) return '—'
            if (s < 60) return `${s}s`
            if (s < 3600) return `${Math.round(s / 60)}m`
            return `${Math.floor(s / 3600)}h ${Math.round((s % 3600) / 60)}m`
        },
        load() {
            return this.mode === 'online' ? this.loadOnline() : this.loadAccounts()
        },
        reload() {
            this.page = 1
            this.loadAccounts()
        },
        debouncedReload() {
            clearTimeout(this.debounceTimer)
            this.debounceTimer = setTimeout(() => this.reload(), 350)
        },
        async loadAccounts() {
            const no = ++this.requestNo
            this.loading = true
            try {
                const data = await apiGet('/players', {
                    ...this.filters,
                    online: this.filters.online ? 1 : 0,
                    sort: this.sort,
                    dir: this.dir,
                    page: this.page,
                    per_page: this.pageSize,
                })
                if (no !== this.requestNo) return // überholte Antwort
                if (!data.success) {
                    this.rows = []
                    this.total = 0
                    return notice(data.msg || 'Could not load players.', 'warning')
                }
                this.rows = data.list
                this.total = data.total
                this.onlineTotal = data.online_total
                this.geoip = data.geoip
            } catch (err) {
                reportError(err, 'Could not load players.')
            } finally {
                if (no === this.requestNo) this.loading = false
            }
        },
        onVisible() {
            // Zurück im Tab: sofort nachziehen statt bis zum nächsten Tick zu warten.
            if (!document.hidden && this.mode === 'online' && !this.loading) this.loadOnline(true)
        },
        async loadLobbySeats() {
            try {
                const data = await apiGet('/live/lobby')
                if (!data.success) {
                    this.lobbyError = data.msg || 'Could not read the lobby.'
                    return
                }
                const running = new Map((data.games || []).map((g) => [g.id, g.running]))
                const seats = new Map()
                for (const p of data.players || []) {
                    seats.set((p.name || '').toLowerCase(), {
                        table: p.game,
                        running: p.game_id !== null && !!running.get(p.game_id),
                        spectator: !!p.spectator,
                    })
                }
                this.lobbySeats = seats
                this.lobbyError = null
            } catch (err) {
                console.error(err)
                this.lobbyError = 'Could not read the lobby.'
            }
        },
        /** silent: Hintergrund-Refresh ohne Ladeanzeige und ohne Fehler-Popup. */
        async loadOnline(silent = false) {
            // Parallel, langsamer (Bot-Login) – die Tabelle wartet nicht darauf.
            this.loadLobbySeats()
            const no = ++this.requestNo
            if (!silent) this.loading = true
            try {
                const data = await apiGet('/players/online')
                if (no !== this.requestNo) return
                this.rows = data.success ? data.list : []
                this.onlineTotal = this.rows.length
                this.geoip = data.geoip
            } catch (err) {
                if (silent) console.error(err)
                else reportError(err, 'Could not load online players.')
            } finally {
                if (no === this.requestNo) this.loading = false
            }
        },
        onModeChange() {
            this.rows = []
            this.page = 1
            this.search = ''
            this.sortProp = 'online_since'
            this.sortOrder = 'descending'
            this.load()
        },
        onPage(p) {
            this.page = p
            if (this.mode === 'accounts') this.loadAccounts()
        },
        onPageSize(size) {
            this.pageSize = size
            this.page = 1
            if (this.mode === 'accounts') this.loadAccounts()
        },
        onServerSort({ prop, order }) {
            this.sort = order ? prop : 'last_login'
            this.dir = order === 'ascending' ? 'asc' : 'desc'
            this.reload()
        },
        filterByIp(ip) {
            this.detail.open = false
            this.mode = 'accounts'
            this.filters = { q: '', ip, ip_scope: 'any', status: 'all', online: false }
            this.reload()
        },
        async openDetail(row) {
            // Alte Daten sofort weg, sonst steht kurz der vorherige Spieler unter dem Spinner.
            const requestId = this.detail.requestId + 1
            this.detail = { open: true, loading: true, data: null, title: row.username || row.nick || '', requestId }
            try {
                const data = await apiGet(`/players/${row.player_id}`)
                // Inzwischen wurde ein anderer Spieler geöffnet (z.B. über "Other accounts").
                if (this.detail.requestId !== requestId) return
                if (!data.success) return notice(data.msg || 'Could not load player.', 'warning')
                this.detail.data = data
                this.detail.title = data.player.username
            } catch (err) {
                if (this.detail.requestId === requestId) reportError(err, 'Could not load player.')
            } finally {
                if (this.detail.requestId === requestId) this.detail.loading = false
            }
        },
        async showIpNicks(ip) {
            if (!ip) return
            this.ipNicks = { open: true, loading: true, ip, geo: null, list: [] }
            try {
                const data = await apiGet('/players/ip', { ip })
                if (!data.success) return notice(data.msg || 'Could not load nicks.', 'warning')
                if (this.ipNicks.ip !== ip) return
                this.ipNicks.list = data.list
                this.ipNicks.geo = data.geo
            } catch (err) {
                reportError(err, 'Could not load nicks.')
            } finally {
                this.ipNicks.loading = false
            }
        },
        askMessage(row) {
            if (!row) return
            this.pm = { open: true, busy: false, row, text: '' }
        },
        async doMessage() {
            const row = this.pm.row
            const text = this.pm.text.trim()
            if (!text || this.pmBytes > PM_MAX_BYTES || this.pm.busy) return
            this.pm.busy = true
            try {
                const payload = row.player_id ? { player_id: row.player_id, text } : { nick: row.username, text }
                const data = await apiPost('/players/message', payload)
                if (data.success) {
                    this.pm.open = false
                    notice(data.msg)
                } else {
                    // Dialog offen lassen, damit der Text nicht verloren geht.
                    notice(data.msg || 'Message failed.', 'error')
                }
            } catch (err) {
                reportError(err, 'Message failed.')
            } finally {
                this.pm.busy = false
            }
        },
        askKickban(row) {
            if (!row) return
            this.kick = { open: true, busy: false, row, resolveReports: true }
        },
        async doKickban() {
            const row = this.kick.row
            this.kick.busy = true
            try {
                const payload = row.player_id
                    ? { player_id: row.player_id, resolve_reports: this.kick.resolveReports ? 1 : 0 }
                    : { nick: row.username }
                const data = await apiPost('/players/kickban', payload)
                this.kick.open = false
                if (data.success) {
                    notice(data.msg + (data.resolved_reports ? ` ${data.resolved_reports} report(s) closed.` : ''))
                } else if (data.code === 'not_online' && row.player_id && !row.banned) {
                    this.offerDbBan(row, data.msg)
                } else {
                    notice(data.msg || 'Kickban failed.', 'error')
                }
                this.refreshAfterAction(row)
            } catch (err) {
                reportError(err, 'Kickban failed.')
            } finally {
                this.kick.busy = false
            }
        },
        async offerDbBan(row, msg) {
            try {
                await confirmAction(`${msg} Ban the account in the database instead (takes effect at the next login)?`, 'Player not online', {
                    confirmButtonText: 'Ban account',
                    confirmButtonClass: 'el-button--danger',
                })
            } catch {
                return
            }
            await this.applyBan(row, true)
        },
        async setBan(row, ban) {
            if (ban) {
                try {
                    await confirmAction(
                        `Ban "${row.username}" in the database? An online player stays connected until the next login – use Kickban to remove them now.`,
                        'Ban account',
                        { confirmButtonText: 'Ban account', confirmButtonClass: 'el-button--danger' }
                    )
                } catch {
                    return
                }
            }
            await this.applyBan(row, ban)
        },
        async applyBan(row, ban) {
            try {
                const data = await apiPost(`/banlist/${row.player_id}`, ban ? { action: 'ban', resolve_reports: 1 } : { action: 'unban' })
                if (!data.success) return notice(data.msg || 'Action failed.', 'error')
                notice(ban ? `${row.username} banned.` : `${row.username} unbanned.`)
                this.refreshAfterAction(row)
            } catch (err) {
                reportError(err, 'Action failed.')
            }
        },
        refreshAfterAction(row) {
            this.load()
            if (this.detail.open && row.player_id && this.detail.data?.player.player_id === row.player_id) this.openDetail(row)
        },
    },
}
</script>

<style scoped>
.player-admin__narrow {
    width: 130px;
}
.player-admin__ip-input {
    width: 190px;
}
.player-admin__dot {
    display: inline-block;
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: var(--el-border-color);
}
.player-admin__dot.is-online {
    background: var(--el-color-success);
    box-shadow: 0 0 0 3px var(--el-color-success-light-8);
}
:deep(.player-admin__ip) {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12.5px;
    cursor: pointer;
    color: inherit;
    text-decoration: none;
    border-bottom: 1px dotted var(--el-text-color-secondary);
}
:deep(.player-admin__ip:hover) {
    color: var(--el-color-primary);
}
:deep(.player-admin__flag) {
    font-family: 'Twemoji Country Flags', 'Apple Color Emoji', 'Segoe UI Emoji', 'Noto Color Emoji', sans-serif;
}
.player-admin__inline-geo {
    margin-left: 8px;
}
.player-admin__table {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.player-admin__table-state {
    flex-shrink: 0;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--el-color-warning);
}
.player-admin__table-state.is-running {
    background: var(--el-color-success);
}
.player-admin__pm-meta {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 6px;
    font-size: 12px;
}
.player-admin__pm-over {
    color: var(--el-color-danger);
    font-weight: 600;
}
.player-admin__pm-hint {
    margin: 8px 0 0;
    font-size: 12px;
}
.player-admin__note {
    margin: 10px 2px 0;
    font-size: 12px;
}
:deep(.player-admin__shared) {
    cursor: pointer;
    font: inherit;
    font-size: 12px;
}
:deep(.player-admin__shared:hover) {
    filter: brightness(0.92);
}
:deep(.player-admin__old td.el-table__cell) {
    opacity: 0.55;
}
.player-admin__overview {
    display: flex;
    gap: 12px;
    align-items: flex-start;
}
.player-admin__overview > .el-descriptions {
    flex: 1;
    min-width: 0;
}
.player-admin__avatar {
    flex: 0 0 auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    width: 96px;
    font-size: 12px;
    text-align: center;
}
.player-admin__avatar .el-image {
    width: 96px;
    height: 96px;
    border-radius: 6px;
    border: 1px solid var(--el-border-color);
    cursor: zoom-in;
}
.player-admin__avatar > .admin-muted {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 96px;
    height: 96px;
    border-radius: 6px;
    border: 1px dashed var(--el-border-color);
}
@media (max-width: 600px) {
    .player-admin__overview {
        flex-direction: column;
    }
}
.player-admin__live {
    color: var(--el-color-success);
    font-weight: 600;
}
.player-admin__detail.is-loading {
    min-height: 240px;
}
.player-admin__detail h4 {
    margin: 22px 0 8px;
}
.el-alert {
    margin: 8px 0 12px;
}
</style>

<style>
/* Tooltip-Inhalt wird per Teleport in <body> gerendert – daher nicht scoped. */
.player-admin__reports-tip {
    max-width: 380px;
    font-size: 12px;
    line-height: 1.4;
}
.player-admin__reports-head {
    margin-bottom: 6px;
}
.player-admin__reports-tip ul {
    margin: 0;
    padding: 0;
    list-style: none;
}
.player-admin__report {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 2px 0;
}
.player-admin__report-date {
    color: var(--el-text-color-secondary);
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}
.player-admin__report-label {
    color: var(--el-text-color-secondary);
}
.player-admin__report-name {
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.player-admin__report-avatar {
    width: 22px;
    height: 22px;
    border-radius: 3px;
    object-fit: cover;
}
.player-admin__reports-hint {
    margin-top: 6px;
}
</style>
