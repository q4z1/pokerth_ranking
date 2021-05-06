<script>
import { Bar } from "vue-chartjs";
export default {
    extends: Bar,
    props: {
        chartData: null,
    },
    mounted() {
        this.renderChart(
        {
            labels: [
                "1st",
                "2nd",
                "3rd",
                "4th",
                "5th",
                "6th",
                "7th",
                "8th",
                "9th",
                "10th",
            ],
            datasets: [
                {
                    backgroundColor: [
                        'rgba(86, 226, 137, 1.0)',
                        'rgba(104, 226, 86, 1.0)',
                        'rgba(174, 226, 86, 1.0)',
                        'rgba(226, 297, 86, 1.0)',
                        'rgba(226, 137, 86, 1.0)',
                        'rgba(226, 84, 104, 1.0)',
                        'rgba(226, 86, 174, 1.0)',
                        'rgba(207, 86, 226, 1.0)',
                        'rgba(138, 86, 226, 1.0)',
                        'rgba(86, 104, 226, 1.0)'
                    ],
                    label: 'Placement',
                    data: this.chartData,
                    options: {
                        legend: {
                            display: false
                        },
                        tootltips: {
                            display: false
                        },
                    }
                }
            ],
        },
        { 
            responsive: true, 
            maintainAspectRatio: false,
            scales: {
                yAxes: [{
                    ticks: {
                        beginAtZero: true
                    }
                }]
            }
        }
        );
    }
};
</script>
