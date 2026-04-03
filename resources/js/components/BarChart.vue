<template>
  <Bar :data="chartDataFormatted" :options="chartOptions" />
</template>

<script>
import { Bar } from 'vue-chartjs'
import {
    Chart as ChartJS,
    BarElement,
    CategoryScale,
    LinearScale,
    Title,
    Tooltip,
    Legend,
} from 'chart.js'

ChartJS.register(BarElement, CategoryScale, LinearScale, Title, Tooltip, Legend)

const PLACEMENT_COLORS = [
    'rgba(86, 226, 137, 1.0)',  'rgba(104, 226, 86, 1.0)',
    'rgba(174, 226, 86, 1.0)',  'rgba(226, 137, 86, 1.0)',
    'rgba(226, 137, 86, 1.0)',  'rgba(226, 84, 104, 1.0)',
    'rgba(226, 86, 174, 1.0)',  'rgba(207, 86, 226, 1.0)',
    'rgba(138, 86, 226, 1.0)',  'rgba(86, 104, 226, 1.0)',
]

export default {
    components: { Bar },
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
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } },
            }
        },
    },
}
</script>
