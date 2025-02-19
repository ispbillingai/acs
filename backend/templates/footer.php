
        <footer class="bg-gray-800 border-t border-gray-700 mt-auto">
            <div class="mx-auto px-6 py-4">
                <p class="text-center text-sm text-gray-400">
                    © <?php echo date('Y'); ?> ACS Dashboard. All rights reserved.
                </p>
            </div>
        </footer>
    </div>
</div>

<!-- Charts Initialization -->
<script>
    // Device Status Chart
    const statusCtx = document.getElementById('deviceStatusChart').getContext('2d');
    new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: ['Online', 'Offline'],
            datasets: [{
                data: [<?php echo $onlineDevices; ?>, <?php echo $offlineDevices; ?>],
                backgroundColor: ['#10B981', '#EF4444'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });

    // Device Activity Chart
    const activityCtx = document.getElementById('deviceActivityChart').getContext('2d');
    new Chart(activityCtx, {
        type: 'line',
        data: {
            labels: ['6h ago', '5h ago', '4h ago', '3h ago', '2h ago', '1h ago', 'Now'],
            datasets: [{
                label: 'Active Devices',
                data: [65, 59, 80, 81, 56, 55, <?php echo $onlineDevices; ?>],
                fill: true,
                borderColor: '#6366F1',
                backgroundColor: 'rgba(99, 102, 241, 0.1)',
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
</script>
</body>
</html>
