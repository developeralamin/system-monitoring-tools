<?php
require_once 'config.php';

class DataExporter {
    private $config;
    
    public function __construct() {
        global $config;
        $this->config = $config;
    }
    
    public function exportMetrics($metrics, $format = null) {
        if ($format === null) {
            $format = $this->config['export']['default_format'];
        }
        
        switch ($format) {
            case 'json':
                return $this->exportJSON($metrics);
            case 'csv':
                return $this->exportCSV($metrics);
            default:
                throw new Exception("Unsupported export format: $format");
        }
    }
    
    private function exportJSON($metrics) {
        return json_encode($metrics, JSON_PRETTY_PRINT);
    }
    
    private function exportCSV($metrics) {
        $output = fopen('php://temp', 'r+');
        
        // Write CPU metrics
        fputcsv($output, ['CPU Metrics']);
        fputcsv($output, ['Overall Usage', 'Cores']);
        fputcsv($output, [
            $metrics['cpu']['overall_usage'],
            $metrics['cpu']['cores']
        ]);
        
        // Write per-core usage
        fputcsv($output, ['Per Core Usage']);
        foreach ($metrics['cpu']['per_core_usage'] as $i => $usage) {
            fputcsv($output, ["Core $i", $usage]);
        }
        
        // Write memory metrics
        fputcsv($output, []);
        fputcsv($output, ['Memory Metrics']);
        fputcsv($output, ['Total', 'Used', 'Free', 'Percent Used']);
        fputcsv($output, [
            $metrics['memory']['total'],
            $metrics['memory']['used'],
            $metrics['memory']['free'],
            $metrics['memory']['percent_used']
        ]);
        
        // Write disk metrics
        fputcsv($output, []);
        fputcsv($output, ['Disk Metrics']);
        fputcsv($output, ['Filesystem', 'Size', 'Used', 'Available', 'Percent Used']);
        foreach ($metrics['disk'] as $disk) {
            fputcsv($output, [
                $disk['filesystem'],
                $disk['size'],
                $disk['used'],
                $disk['available'],
                $disk['percent_used']
            ]);
        }
        
        // Write network metrics
        if (isset($metrics['network'])) {
            fputcsv($output, []);
            fputcsv($output, ['Network Metrics']);
            fputcsv($output, ['Bytes Received', 'Bytes Sent', 'Errors In', 'Errors Out']);
            fputcsv($output, [
                $metrics['network']['bytes_received'],
                $metrics['network']['bytes_sent'],
                $metrics['network']['errors_in'],
                $metrics['network']['errors_out']
            ]);
        }
        
        // Write top processes
        fputcsv($output, []);
        fputcsv($output, ['Top Processes']);
        fputcsv($output, ['Name', 'PID', 'Memory Usage', 'CPU Time', 'Status']);
        foreach ($metrics['processes'] as $process) {
            fputcsv($output, [
                $process['name'],
                $process['pid'],
                $process['memory_usage'],
                $process['cpu_time'],
                $process['status']
            ]);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }
    
    public function getExportFormats() {
        return $this->config['export']['formats'];
    }
    
    public function exportHistoricalData($timeframe = 'hourly') {
        $data = [];
        $base_dir = 'metrics_data';
        
        switch ($timeframe) {
            case 'hourly':
                $dir = "$base_dir/hourly";
                $pattern = 'hour_*.json';
                break;
            case 'daily':
                $dir = "$base_dir/daily";
                $pattern = 'day_*.json';
                break;
            case 'weekly':
                $dir = "$base_dir/weekly";
                $pattern = 'week_*.json';
                break;
            default:
                throw new Exception("Invalid timeframe: $timeframe");
        }
        
        if (is_dir($dir)) {
            foreach (glob("$dir/$pattern") as $file) {
                $metrics = json_decode(file_get_contents($file), true);
                if ($metrics) {
                    $data[] = $metrics;
                }
            }
        }
        
        return $data;
    }
} 