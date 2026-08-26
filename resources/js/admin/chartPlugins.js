/**
 * Kleine Chart.js-Plugins für den Server-Log-Report, die dem Original-SVG
 * (tools/analyze_server_log.py) näherkommen: ein farbiges Band fürs
 * Ruhefenster, eine Wochenmittel-Linie über den Balken, und permanente
 * Werte-Labels statt nur Hover-Tooltips.
 *
 * Bewusst pro Chart-Instanz übergeben (`:plugins="[...]"` in vue-chartjs),
 * nicht global via ChartJS.register() - sonst würden sie jeden Chart.js-Graph
 * in der App betreffen, nicht nur diese Seite.
 */

function slotWidth(scale) {
    return scale.getPixelForTick(1) - scale.getPixelForTick(0)
}

/** Farbige Fläche über einen Stundenbereich, z. B. das erkannte Ruhefenster. */
export const nightBandPlugin = {
    id: 'nightBand',
    beforeDatasetsDraw(chart, args, opts) {
        if (!opts || opts.from == null || opts.to == null) return
        const { ctx, chartArea, scales } = chart
        const xScale = scales.x
        const slot = slotWidth(xScale)
        const xFrom = xScale.getPixelForTick(opts.from) - slot / 2
        const xTo = xScale.getPixelForTick(opts.to) - slot / 2
        ctx.save()
        ctx.fillStyle = opts.color || 'rgba(235, 104, 52, 0.12)'
        ctx.fillRect(xFrom, chartArea.top, xTo - xFrom, chartArea.bottom - chartArea.top)
        if (opts.label) {
            ctx.fillStyle = opts.labelColor || 'rgba(193, 74, 31, 1)'
            ctx.font = "600 11px 'Inter', sans-serif"
            ctx.textAlign = 'center'
            ctx.fillText(opts.label, (xFrom + xTo) / 2, chartArea.top - 6)
        }
        ctx.restore()
    },
}

/**
 * Wie nightBandPlugin, aber für mehrere Bereiche zugleich - der Webserver-Log
 * markiert damit jedes Zeitfenster, in dem der Cloudflare-Filter aktiv war.
 * opts.bands: Array aus {from, to, color?} (Tick-Indizes, wie bei
 * nightBandPlugin). Ein band-eigenes color überschreibt opts.color - genutzt,
 * um bestätigte Filter-Laufzeiten (rot) von nur gemessenen, nicht
 * bestätigten Schwellwert-Überschreitungen (gedeckter) zu unterscheiden.
 */
export const attackBandsPlugin = {
    id: 'attackBands',
    beforeDatasetsDraw(chart, args, opts) {
        if (!opts || !opts.bands || !opts.bands.length) return
        const { ctx, chartArea, scales } = chart
        const xScale = scales.x
        const slot = slotWidth(xScale)
        ctx.save()
        opts.bands.forEach((band) => {
            ctx.fillStyle = band.color || opts.color || 'rgba(226, 84, 104, 0.16)'
            const xFrom = xScale.getPixelForTick(band.from) - slot / 2
            const xTo = xScale.getPixelForTick(band.to) + slot / 2
            ctx.fillRect(xFrom, chartArea.top, xTo - xFrom, chartArea.bottom - chartArea.top)
        })
        ctx.restore()
    },
}

/** Dashed Linie mit Label über den Balken eines Wochenblocks, z. B. der Wochenschnitt. */
export const weekMeansPlugin = {
    id: 'weekMeans',
    afterDatasetsDraw(chart, args, opts) {
        if (!opts || !opts.weeks || !opts.weeks.length) return
        const { ctx, scales } = chart
        const xScale = scales.x
        const yScale = scales.y
        const slot = slotWidth(xScale)
        opts.weeks.forEach((w) => {
            const x1 = xScale.getPixelForTick(w.from) - slot / 2 + 3
            const x2 = xScale.getPixelForTick(w.to) + slot / 2 - 3
            const y = yScale.getPixelForValue(w.mean)
            ctx.save()
            ctx.strokeStyle = opts.color || 'rgba(11, 11, 11, 0.85)'
            ctx.lineWidth = 2
            ctx.beginPath()
            ctx.moveTo(x1, y)
            ctx.lineTo(x2, y)
            ctx.stroke()
            ctx.fillStyle = opts.color || 'rgba(11, 11, 11, 0.85)'
            ctx.font = "600 11px 'Inter', sans-serif"
            ctx.textAlign = 'center'
            ctx.fillText(`avg ${w.mean}`, (x1 + x2) / 2, y - 8)
            ctx.restore()
        })
    },
}

/**
 * Permanente Werte-Labels an Punkten/Balken.
 * opts.mode: 'all' (jeder Punkt, Nullen übersprungen) oder 'edges' (nur
 * erster/letzter Punkt, wie Panel A im Original).
 */
export const valueLabelsPlugin = {
    id: 'valueLabels',
    afterDatasetsDraw(chart, args, opts) {
        if (!opts || !opts.show) return
        const { ctx } = chart
        const dsIndex = opts.datasetIndex ?? 0
        const meta = chart.getDatasetMeta(dsIndex)
        if (!meta || meta.hidden) return
        const dataset = chart.data.datasets[dsIndex]
        const points = meta.data
        const indices = opts.mode === 'edges'
            ? [0, points.length - 1].filter((i, idx, arr) => arr.indexOf(i) === idx && i >= 0)
            : points.map((_, i) => i)

        indices.forEach((i) => {
            const value = dataset.data[i]
            if (value === null || value === undefined || value === 0) return
            const el = points[i]
            if (!el) return
            const { x, y } = el.tooltipPosition ? el.tooltipPosition() : el
            ctx.save()
            ctx.fillStyle = opts.color || 'rgba(0, 0, 0, 0.85)'
            ctx.font = "600 11px 'Inter', sans-serif"
            ctx.textAlign = opts.align === 'end' ? 'end' : 'center'
            const text = opts.format ? opts.format(value) : String(value)
            ctx.fillText(text, x, y - (opts.offset ?? 8))
            ctx.restore()
        })
    },
}
