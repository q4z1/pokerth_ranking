/**
 * Kleine Render-Zellen der Spielerverwaltung (PlayerAdmin.vue). Ausgelagert,
 * damit die Komponente selbst nur noch Daten und Aktionen enthält. Die Styles
 * (player-admin__*) liegen weiterhin in PlayerAdmin.vue (:deep).
 */
import { h, resolveComponent } from 'vue'
import { API_BASE, formatDate, stateMeta } from './adminUtils.js'

export function flag(cc) {
    if (!cc || !/^[A-Z]{2}$/.test(cc)) return ''
    return String.fromCodePoint(...[...cc].map((c) => 0x1f1e6 + c.charCodeAt(0) - 65))
}

/** IP in Monospace; Klick filtert die Spielerliste nach genau dieser IP. */
export const IpCell = {
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

export const GeoCell = {
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
export const SharedCell = {
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
export const ReportsCell = {
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
