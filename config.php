<?php
// Configuration file for system monitoring

// Default alert thresholds
$config = [
    'alerts' => [
        'cpu' => [
            'warning' => 80,  // CPU usage warning threshold (%)
            'critical' => 90, // CPU usage critical threshold (%)
            'duration' => 300 // Duration in seconds before alert is triggered
        ],
        'memory' => [
            'warning' => 80,  // Memory usage warning threshold (%)
            'critical' => 90, // Memory usage critical threshold (%)
            'duration' => 300 // Duration in seconds before alert is triggered
        ],
        'disk' => [
            'warning' => 85,  // Disk usage warning threshold (%)
            'critical' => 95, // Disk usage critical threshold (%)
            'duration' => 3600 // Duration in seconds before alert is triggered
        ],
        'swap' => [
            'warning' => 60,  // Swap usage warning threshold (%)
            'critical' => 80, // Swap usage critical threshold (%)
            'duration' => 300 // Duration in seconds before alert is triggered
        ]
    ],
    'monitoring' => [
        'interval' => 60,     // Monitoring interval in seconds
        'retention' => [
            'detailed' => 86400,    // 24 hours in seconds
            'hourly' => 604800,     // 7 days in seconds
            'daily' => 2592000,     // 30 days in seconds
            'weekly' => 31536000    // 365 days in seconds
        ]
    ],
    'display' => [
        'theme' => 'light',   // Default theme (light/dark)
        'refresh_rate' => 5,  // Page refresh rate in seconds
        'max_processes' => 20 // Maximum number of processes to display
    ],
    'self_healing' => [
        'enabled' => false,   // Enable/disable self-healing
        'actions' => [
            'high_cpu' => [
                'enabled' => false,
                'threshold' => 95,
                'duration' => 300,
                'action' => 'taskkill /F /PID {pid}' // Action to execute
            ],
            'low_disk_space' => [
                'enabled' => false,
                'threshold' => 95,
                'duration' => 3600,
                'action' => 'cleanmgr /sagerun:1' // Run disk cleanup
            ]
        ]
    ],
    'export' => [
        'formats' => ['json', 'csv'],
        'default_format' => 'json'
    ]
];

// Load user configuration if exists
$user_config_file = 'user_config.json';
if (file_exists($user_config_file)) {
    $user_config = json_decode(file_get_contents($user_config_file), true);
    if ($user_config) {
        $config = array_replace_recursive($config, $user_config);
    }
}

// Function to save user configuration
function saveUserConfig($new_config) {
    global $config;
    $config = array_replace_recursive($config, $new_config);
    file_put_contents('user_config.json', json_encode($config, JSON_PRETTY_PRINT));
    return true;
}

// Function to validate thresholds
function validateThresholds($thresholds) {
    foreach ($thresholds as $metric => $values) {
        if (!isset($values['warning']) || !isset($values['critical'])) {
            return false;
        }
        if ($values['warning'] >= $values['critical']) {
            return false;
        }
        if ($values['warning'] < 0 || $values['warning'] > 100 ||
            $values['critical'] < 0 || $values['critical'] > 100) {
            return false;
        }
    }
    return true;
} 