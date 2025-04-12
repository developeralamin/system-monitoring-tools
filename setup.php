<?php
// setup.php - Initialize the monitoring system

// Create required directories
$directories = [
    'metrics_data',
    'metrics_data/hourly',
    'metrics_data/daily',
    'metrics_data/weekly'
];

foreach ($directories as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
        echo "Created directory: $dir\n";
    } else {
        echo "Directory exists: $dir\n";
    }
}

// Create default configuration if it doesn't exist
if (!file_exists('user_config.json')) {
    $default_config = [
        'alerts' => [
            'cpu' => [
                'warning' => 80,
                'critical' => 90,
                'duration' => 300
            ],
            'memory' => [
                'warning' => 80,
                'critical' => 90,
                'duration' => 300
            ],
            'disk' => [
                'warning' => 85,
                'critical' => 95,
                'duration' => 3600
            ],
            'swap' => [
                'warning' => 60,
                'critical' => 80,
                'duration' => 300
            ]
        ],
        'monitoring' => [
            'interval' => 60
        ],
        'display' => [
            'theme' => 'light',
            'refresh_rate' => 5
        ],
        'self_healing' => [
            'enabled' => false
        ]
    ];
    
    file_put_contents('user_config.json', json_encode($default_config, JSON_PRETTY_PRINT));
    echo "Created default configuration file\n";
} else {
    echo "Configuration file exists\n";
}

// Create scheduled task for Windows
$task_name = "SystemMonitoring";
$script_path = realpath('metrics_collector.php');
$php_path = "php";

// Create batch file for the task
$batch_content = "@echo off\n$php_path \"$script_path\"\n";
file_put_contents('collect_metrics.bat', $batch_content);
echo "Created collection batch file\n";

// Create the scheduled task
$interval_minutes = 1; // Run every minute
$command = "schtasks /create /tn \"$task_name\" /tr \"" . realpath('collect_metrics.bat') . 
          "\" /sc minute /mo $interval_minutes /ru System /f";

exec($command, $output, $return_var);

if ($return_var === 0) {
    echo "Created scheduled task successfully\n";
} else {
    echo "Error creating scheduled task. Please run this script as administrator.\n";
    echo "You can manually create a scheduled task to run collect_metrics.bat every minute.\n";
}

echo "\nSetup complete! The monitoring system is now ready to use.\n";
echo "You can access the monitoring interface through your web browser.\n";
?>