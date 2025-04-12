<?php
// metrics_collector.php for Windows - Collects system metrics and saves as JSON

// Function to collect system metrics on Windows
function collectSystemMetrics() {
    $metrics = [
        'timestamp' => date('Y-m-d H:i:s'),
        'cpu' => [],
        'memory' => [],
        'processes' => [],
        'network' => [],
        'disk_io' => []
    ];
    
    // CPU Usage per core using wmic
    exec('wmic cpu get NumberOfCores /value', $core_output);
    $num_cores = 1;
    foreach ($core_output as $line) {
        if (strpos($line, 'NumberOfCores') !== false) {
            $num_cores = intval(explode('=', $line)[1]);
            break;
        }
    }
    
    // Get CPU usage per core using typeperf
    $cpu_per_core = [];
    exec('typeperf "\Processor(*)\% Processor Time" -sc 1', $cpu_core_output);
    foreach ($cpu_core_output as $i => $line) {
        if ($i > 0 && $i < count($cpu_core_output) - 1) {
            $values = str_getcsv($line);
            for ($j = 1; $j < count($values); $j++) {
                $cpu_per_core[] = round(floatval($values[$j]), 2);
            }
        }
    }
    
    // Overall CPU Usage using wmic
    exec('wmic cpu get LoadPercentage /value', $cpu_output);
    $cpu_usage = 0;
    foreach ($cpu_output as $line) {
        if (strpos($line, 'LoadPercentage') !== false) {
            $cpu_usage = intval(explode('=', $line)[1]);
            break;
        }
    }
    
    $metrics['cpu'] = [
        'overall_usage' => $cpu_usage,
        'cores' => $num_cores,
        'per_core_usage' => $cpu_per_core,
        'load_average' => [
            '1min' => $cpu_usage / 100,
            '5min' => $cpu_usage / 100,
            '15min' => $cpu_usage / 100
        ]
    ];
    
    // Enhanced Memory Information
    exec('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize,TotalVirtualMemorySize,FreeVirtualMemory /value', $mem_output);
    $total_memory = 0;
    $free_memory = 0;
    $total_virtual = 0;
    $free_virtual = 0;
    
    foreach ($mem_output as $line) {
        if (strpos($line, 'TotalVisibleMemorySize') !== false) {
            $total_memory = intval(explode('=', $line)[1]);
        } else if (strpos($line, 'FreePhysicalMemory') !== false) {
            $free_memory = intval(explode('=', $line)[1]);
        } else if (strpos($line, 'TotalVirtualMemorySize') !== false) {
            $total_virtual = intval(explode('=', $line)[1]);
        } else if (strpos($line, 'FreeVirtualMemory') !== false) {
            $free_virtual = intval(explode('=', $line)[1]);
        }
    }
    
    $used_memory = $total_memory - $free_memory;
    $memory_percent = round(($used_memory / $total_memory) * 100, 2);
    
    $metrics['memory'] = [
        'total' => $total_memory,
        'free' => $free_memory,
        'used' => $used_memory,
        'percent_used' => $memory_percent,
        'virtual_total' => $total_virtual,
        'virtual_free' => $free_virtual,
        'virtual_used' => $total_virtual - $free_virtual,
        'virtual_percent_used' => round((($total_virtual - $free_virtual) / $total_virtual) * 100, 2)
    ];
    
    // Enhanced Process Information with sorting by memory usage
    exec('tasklist /FO CSV /NH /V', $process_output);
    $processes = [];
    
    foreach ($process_output as $line) {
        $process_data = str_getcsv($line);
        if (count($process_data) >= 8) {
            // Convert memory string to number for sorting
            $memory_str = str_replace([',', ' K'], '', $process_data[4]);
            $memory_kb = intval($memory_str);
            
            $processes[] = [
                'name' => $process_data[0],
                'pid' => $process_data[1],
                'memory_usage' => $process_data[4],
                'memory_kb' => $memory_kb,
                'cpu_time' => $process_data[7],
                'status' => $process_data[3],
                'user' => $process_data[6],
                'command' => $process_data[0]
            ];
        }
    }
    
    // Sort processes by memory usage
    usort($processes, function($a, $b) {
        return $b['memory_kb'] - $a['memory_kb'];
    });
    
    $metrics['processes'] = array_slice($processes, 0, 20); // Top 20 processes
    
    // Network Activity using netstat
    exec('netstat -e', $network_output);
    if (count($network_output) >= 2) {
        $headers = preg_split('/\s+/', trim($network_output[0]));
        $values = preg_split('/\s+/', trim($network_output[1]));
        
        $metrics['network'] = [
            'bytes_received' => intval($values[1]),
            'bytes_sent' => intval($values[2]),
            'errors_in' => isset($values[3]) ? intval($values[3]) : 0,
            'errors_out' => isset($values[4]) ? intval($values[4]) : 0
        ];
    }
    
    // Enhanced Disk Information with I/O stats and SSD detection
    // Get disk information including media type
    $drive_types = array(); // Initialize array
    exec('wmic diskdrive get Caption,Size,MediaType /format:csv', $drive_info);
    
    if (!empty($drive_info)) {
        foreach ($drive_info as $i => $line) {
            if ($i === 0 || empty($line)) continue;
            $drive_data = str_getcsv($line);
            if (count($drive_data) >= 3) {
                $caption = trim($drive_data[1]);
                $media_type = isset($drive_data[3]) ? trim($drive_data[3]) : '';
                $drive_types[$caption] = $media_type;
            }
        }
    }

    // Get logical disk information
    exec('wmic logicaldisk get Caption,FreeSpace,Size,ProviderName /format:csv', $disk_output);
    $disk_info = [];
    
    foreach ($disk_output as $i => $line) {
        if (empty($line) || $i === 0) continue;
        
        $disk_data = str_getcsv($line);
        if (count($disk_data) >= 3 && !empty($disk_data[1])) {
            $drive = $disk_data[1];
            $free_space = isset($disk_data[2]) ? (float)$disk_data[2] : 0;
            $total_size = isset($disk_data[3]) ? (float)$disk_data[3] : 0;
            
            if ($total_size > 0) {
                $used_space = $total_size - $free_space;
                $percent_used = round(($used_space / $total_size) * 100);
                
                // Get disk performance data
                exec("wmic path Win32_PerfFormattedData_PerfDisk_LogicalDisk where Name='$drive' get DiskReadBytesPerSec,DiskWriteBytesPerSec /format:csv", $perf_output);
                $read_speed = $write_speed = 0;
                
                foreach ($perf_output as $perf_line) {
                    if (empty($perf_line) || strpos($perf_line, 'Node') !== false) continue;
                    $perf_data = str_getcsv($perf_line);
                    if (count($perf_data) >= 3) {
                        $read_speed = isset($perf_data[1]) ? intval($perf_data[1]) : 0;
                        $write_speed = isset($perf_data[2]) ? intval($perf_data[2]) : 0;
                    }
                }
                
                // Find corresponding physical drive
                $drive_type = "HDD"; // default
                foreach ($drive_types as $caption => $media_type) {
                    if (strpos($caption, trim($drive, ':')) !== false) {
                        $drive_type = (stripos($media_type, 'SSD') !== false || 
                                     stripos($caption, 'SSD') !== false) ? 'SSD' : 'HDD';
                        break;
                    }
                }
                
                $disk_info[] = [
                    'filesystem' => $drive,
                    'type' => $drive_type,
                    'size' => round($total_size / (1024 * 1024 * 1024), 2) . ' GB',
                    'size_bytes' => $total_size,
                    'used' => round($used_space / (1024 * 1024 * 1024), 2) . ' GB',
                    'used_bytes' => $used_space,
                    'available' => round($free_space / (1024 * 1024 * 1024), 2) . ' GB',
                    'available_bytes' => $free_space,
                    'percent_used' => $percent_used,
                    'mounted_on' => $drive,
                    'performance' => [
                        'read_speed' => round($read_speed / (1024 * 1024), 2) . ' MB/s',
                        'write_speed' => round($write_speed / (1024 * 1024), 2) . ' MB/s'
                    ]
                ];
            }
        }
    }
    
    // If wmic command failed, try alternative method
    if (empty($disk_info)) {
        exec('fsutil volume diskfree c:', $alt_output);
        if (!empty($alt_output)) {
            $total = $free = $used = 0;
            foreach ($alt_output as $line) {
                if (strpos($line, 'Total # of bytes') !== false) {
                    $total = (float)preg_replace('/[^0-9]/', '', $line);
                } else if (strpos($line, 'Total # of free bytes') !== false) {
                    $free = (float)preg_replace('/[^0-9]/', '', $line);
                }
            }
            
            if ($total > 0) {
                $used = $total - $free;
                $percent_used = round(($used / $total) * 100);
                
                // Try to detect if it's an SSD using PowerShell
                exec('powershell -command "Get-PhysicalDisk | Select MediaType"', $ps_output);
                $is_ssd = false;
                foreach ($ps_output as $line) {
                    if (stripos($line, 'SSD') !== false) {
                        $is_ssd = true;
                        break;
                    }
                }
                
                $disk_info[] = [
                    'filesystem' => 'C:',
                    'type' => $is_ssd ? 'SSD' : 'HDD',
                    'size' => round($total / (1024 * 1024 * 1024), 2) . ' GB',
                    'size_bytes' => $total,
                    'used' => round($used / (1024 * 1024 * 1024), 2) . ' GB',
                    'used_bytes' => $used,
                    'available' => round($free / (1024 * 1024 * 1024), 2) . ' GB',
                    'available_bytes' => $free,
                    'percent_used' => $percent_used,
                    'mounted_on' => 'C:',
                    'performance' => [
                        'read_speed' => 'N/A',
                        'write_speed' => 'N/A'
                    ]
                ];
            }
        }
    }
    
    $metrics['disk'] = $disk_info;
    
    // Disk I/O Performance
    exec('wmic diskdrive get Caption,BytesPerSecond /format:csv', $disk_io_output);
    $disk_io = [];
    foreach ($disk_io_output as $i => $line) {
        if ($i === 0) continue;
        
        $io_data = str_getcsv($line);
        if (count($io_data) >= 3) {
            $disk_io[] = [
                'device' => $io_data[1],
                'bytes_per_second' => isset($io_data[2]) ? intval($io_data[2]) : 0
            ];
        }
    }
    $metrics['disk_io'] = $disk_io;
    
    return $metrics;
}

// Enhanced saveMetrics function with data retention
function saveMetrics($metrics) {
    $data_dir = 'metrics_data';
    $hourly_dir = $data_dir . '/hourly';
    $daily_dir = $data_dir . '/daily';
    $weekly_dir = $data_dir . '/weekly';
    
    // Create directories if they don't exist
    foreach ([$data_dir, $hourly_dir, $daily_dir, $weekly_dir] as $dir) {
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
    }
    
    // Save current metrics
    $current_file = $data_dir . '/metrics_' . date('Y-m-d_H-i-s') . '.json';
    file_put_contents($current_file, json_encode($metrics, JSON_PRETTY_PRINT));
    file_put_contents($data_dir . '/latest_metrics.json', json_encode($metrics, JSON_PRETTY_PRINT));
    
    // Save hourly average
    $hour_file = $hourly_dir . '/hour_' . date('Y-m-d_H') . '.json';
    saveAggregatedMetrics($hour_file, $metrics);
    
    // Save daily average (once per day)
    if (date('H:i') === '23:59') {
        $day_file = $daily_dir . '/day_' . date('Y-m-d') . '.json';
        saveAggregatedMetrics($day_file, $metrics);
    }
    
    // Save weekly average (end of week)
    if (date('w H:i') === '0 23:59') {
        $week_file = $weekly_dir . '/week_' . date('Y-m-d') . '.json';
        saveAggregatedMetrics($week_file, $metrics);
    }
    
    // Cleanup old files (keep last 24 hours of detailed metrics)
    $cleanup_time = time() - (24 * 3600);
    foreach (glob($data_dir . '/metrics_*.json') as $file) {
        if (filemtime($file) < $cleanup_time) {
            unlink($file);
        }
    }
    
    return $current_file;
}

// Helper function to save aggregated metrics
function saveAggregatedMetrics($file, $new_metrics) {
    if (file_exists($file)) {
        $existing = json_decode(file_get_contents($file), true);
        $count = $existing['count'] + 1;
        
        // Average the numeric values
        $metrics = averageMetrics($existing, $new_metrics, $count);
    } else {
        $metrics = $new_metrics;
        $metrics['count'] = 1;
    }
    
    file_put_contents($file, json_encode($metrics, JSON_PRETTY_PRINT));
}

// Helper function to average metrics
function averageMetrics($existing, $new, $count) {
    $result = [];
    
    foreach ($new as $key => $value) {
        if ($key === 'count') continue;
        
        if (is_numeric($value)) {
            $prev = isset($existing[$key]) ? $existing[$key] * ($count - 1) : 0;
            $result[$key] = ($prev + $value) / $count;
        } elseif (is_array($value)) {
            $result[$key] = averageMetrics(
                isset($existing[$key]) ? $existing[$key] : [],
                $value,
                $count
            );
        } else {
            $result[$key] = $value;
        }
    }
    
    $result['count'] = $count;
    return $result;
}

// Check if script is run from command line or via web
if (php_sapi_name() === 'cli') {
    // Command line execution
    $metrics = collectSystemMetrics();
    $file = saveMetrics($metrics);
    echo "Metrics saved to $file\n";
} else {
    // Web execution - used for API endpoint
    header('Content-Type: application/json');
    
    $metrics = collectSystemMetrics();
    $file = saveMetrics($metrics);
    
    echo json_encode([
        'success' => true,
        'message' => 'Metrics collected successfully',
        'file' => $file,
        'metrics' => $metrics
    ]);
}
?>