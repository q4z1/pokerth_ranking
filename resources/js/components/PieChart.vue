<template>
  <Pie :data="chartDataFormatted" :options="chartOptions" />
</template>

<script>
import { Pie } from 'vue-chartjs'
import {
    Chart as ChartJS,
    ArcElement,
    Title,
    Tooltip,
    Legend,
} from 'chart.js'
import { PLACEMENT_COLORS, CHART_TEXT_COLOR } from '../chartColors.js'

ChartJS.register(ArcElement, Title, Tooltip, Legend)

// Canvas-Hintergrund transparent (sonst weiß bei dark mode)
const transparentBg = {
    id: 'transparentBg',
    beforeDraw: (chart) => {
        const ctx = chart.canvas.getContext('2d')
        ctx.save()
        ctx.globalCompositeOperation = 'destination-over'
        ctx.fillStyle = 'transparent'
        ctx.fillRect(0, 0, chart.width, chart.height)
        ctx.restore()
    }
}
ChartJS.register(transparentBg)

export default {
    components: { Pie },
    props: { chartData: { default: null } },
    computed: {
        chartDataFormatted() {
            return {
                labels: ['1st','2nd','3rd','4th','5th','6th','7th','8th','9th','10th'],
                datasets: [{
                    label: 'Placement',
                    backgroundColor: PLACEMENT_COLORS,
                    data: this.chartData ?? [],
                }],
            }
        },
        chartOptions() {
            return {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { labels: { color: CHART_TEXT_COLOR } },
                },
            }
        },
    },
}
</script>
