<?php
// setup_cron.php - Set up scheduled task for metric collection

// Get the absolute path to the metrics collector
$script_path = realpath('metrics_collector.php');
if (!$script_path) {
    die("Error: Could not find metrics_collector.php\n");
}

// Get PHP executable path
$php_path = "php";

// Create batch file for the scheduled task
$batch_file = 'collect_metrics.bat';
$batch_content = "@echo off\n" .
                "cd \"" . dirname($script_path) . "\"\n" .
                "$php_path \"$script_path\"\n";

file_put_contents($batch_file, $batch_content);
echo "Created collection batch file: $batch_file\n";

// Task settings
$task_name = "SystemMonitoring";
$interval_minutes = 1; // Run every minute

// Create the scheduled task
$command = "schtasks /create /tn \"$task_name\" " .
          "/tr \"" . realpath($batch_file) . "\" " .
          "/sc minute /mo $interval_minutes " .
          "/ru System /f";

exec($command, $output, $return_var);

if ($return_var === 0) {
    echo "Successfully created scheduled task '$task_name'\n";
    echo "The system will collect metrics every $interval_minutes minute(s)\n";
} else {
    echo "Error creating scheduled task. Please ensure you're running this script as administrator.\n";
    echo "Error details:\n";
    foreach ($output as $line) {
        echo $line . "\n";
    }
    echo "\nAlternative setup instructions:\n";
    echo "1. Open Task Scheduler (taskschd.msc)\n";
    echo "2. Create a new basic task\n";
    echo "3. Name it '$task_name'\n";
    echo "4. Set trigger to: Every $interval_minutes minute(s)\n";
    echo "5. Set action to: Start a program\n";
    echo "6. Program/script: " . realpath($batch_file) . "\n";
}

// Verify the task was created
exec("schtasks /query /tn \"$task_name\"", $verify_output, $verify_status);

if ($verify_status === 0) {
    echo "\nTask verification successful. The monitoring system is ready!\n";
} else {
    echo "\nWarning: Could not verify task creation. Please check Task Scheduler manually.\n";
}
?>
