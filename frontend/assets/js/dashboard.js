document.addEventListener('DOMContentLoaded', () => {
    // Chart Defaults
    Chart.defaults.color = '#A8A29E';
    Chart.defaults.font.family = 'Outfit, sans-serif';

    // Main Chart
    const ctx = document.getElementById('mainChart').getContext('2d');
    const gradient = ctx.createLinearGradient(0, 0, 0, 300);
    gradient.addColorStop(0, 'rgba(255, 126, 33, 0.4)');
    gradient.addColorStop(1, 'rgba(255, 126, 33, 0)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            datasets: [{
                label: 'Profit',
                data: [5, 12, 10, 22, 28, 26, 35, 32, 38, 32, 36, 40],
                borderColor: '#FF7E21',
                borderWidth: 3,
                tension: 0.4,
                fill: true,
                backgroundColor: gradient,
                pointRadius: 0,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: '#FF7E21',
                pointHoverBorderColor: '#fff',
                pointHoverBorderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: '#292524' },
                    ticks: { callback: value => value + 'k' }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });

    // Mini Charts Helper
    const createMiniChart = (id, data, color) => {
        const mctx = document.getElementById(id).getContext('2d');
        new Chart(mctx, {
            type: 'line',
            data: {
                labels: [1, 2, 3, 4, 5, 6, 7],
                datasets: [{
                    data: data,
                    borderColor: color,
                    borderWidth: 2,
                    tension: 0.4,
                    pointRadius: 0,
                    fill: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { display: false },
                    y: { display: false }
                }
            }
        });
    };

    createMiniChart('miniChart1', [10, 15, 8, 12, 20, 18, 25], '#FF7E21');
    createMiniChart('miniChart2', [5, 10, 15, 10, 12, 14, 13], '#FF7E21');
    createMiniChart('miniChart3', [12, 10, 14, 13, 16, 15, 18], '#FF7E21');
});
