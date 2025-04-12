<?php
require_once 'config.php';

class AlertHandler {
    private $config;
    private $alerts_file = 'metrics_data/alerts.json';
    private $alert_history = [];
    
    public function __construct() {
        global $config;
        $this->config = $config;
        $this->loadAlertHistory();
    }
    
    private function loadAlertHistory() {
        if (file_exists($this->alerts_file)) {
            $this->alert_history = json_decode(file_get_contents($this->alerts_file), true) ?: [];
        }
    }
    
    private function saveAlertHistory() {
        file_put_contents($this->alerts_file, json_encode($this->alert_history, JSON_PRETTY_PRINT));
    }
    
    public function checkMetrics($metrics) {
        $alerts = [];
        
        // Check CPU usage
        if (isset($metrics['cpu']['overall_usage'])) {
            $alerts = array_merge($alerts, $this->checkThreshold(
                'cpu',
                $metrics['cpu']['overall_usage'],
                'CPU Usage'
            ));
        }
        
        // Check memory usage
        if (isset($metrics['memory']['percent_used'])) {
            $alerts = array_merge($alerts, $this->checkThreshold(
                'memory',
                $metrics['memory']['percent_used'],
                'Memory Usage'
            ));
        }
        
        // Check disk usage
        if (isset($metrics['disk'])) {
            foreach ($metrics['disk'] as $disk) {
                if (isset($disk['percent_used'])) {
                    $alerts = array_merge($alerts, $this->checkThreshold(
                        'disk',
                        $disk['percent_used'],
                        "Disk Usage ({$disk['filesystem']})"
                    ));
                }
            }
        }
        
        // Check virtual memory (swap) usage
        if (isset($metrics['memory']['virtual_percent_used'])) {
            $alerts = array_merge($alerts, $this->checkThreshold(
                'swap',
                $metrics['memory']['virtual_percent_used'],
                'Swap Usage'
            ));
        }
        
        // Process alerts and trigger self-healing if enabled
        foreach ($alerts as $alert) {
            $this->processAlert($alert, $metrics);
        }
        
        return $alerts;
    }
    
    private function checkThreshold($metric, $value, $description) {
        $alerts = [];
        $thresholds = $this->config['alerts'][$metric];
        $current_time = time();
        
        // Check critical threshold
        if ($value >= $thresholds['critical']) {
            $alerts[] = [
                'level' => 'critical',
                'metric' => $metric,
                'value' => $value,
                'threshold' => $thresholds['critical'],
                'description' => $description,
                'timestamp' => $current_time
            ];
        }
        // Check warning threshold
        else if ($value >= $thresholds['warning']) {
            $alerts[] = [
                'level' => 'warning',
                'metric' => $metric,
                'value' => $value,
                'threshold' => $thresholds['warning'],
                'description' => $description,
                'timestamp' => $current_time
            ];
        }
        
        return $alerts;
    }
    
    private function processAlert($alert, $metrics) {
        // Add alert to history
        $this->alert_history[] = $alert;
        
        // Keep only last 100 alerts
        if (count($this->alert_history) > 100) {
            array_shift($this->alert_history);
        }
        
        // Save alert history
        $this->saveAlertHistory();
        
        // Check if self-healing is enabled and should be triggered
        if ($this->config['self_healing']['enabled']) {
            $this->triggerSelfHealing($alert, $metrics);
        }
    }
    
    private function triggerSelfHealing($alert, $metrics) {
        $actions = $this->config['self_healing']['actions'];
        
        switch ($alert['metric']) {
            case 'cpu':
                if ($actions['high_cpu']['enabled'] && 
                    $alert['value'] >= $actions['high_cpu']['threshold']) {
                    $this->executeHighCPUAction($metrics);
                }
                break;
                
            case 'disk':
                if ($actions['low_disk_space']['enabled'] && 
                    $alert['value'] >= $actions['low_disk_space']['threshold']) {
                    $this->executeDiskCleanupAction($alert['description']);
                }
                break;
        }
    }
    
    private function executeHighCPUAction($metrics) {
        // Find the process using the most CPU
        if (!empty($metrics['processes'])) {
            $top_process = $metrics['processes'][0];
            $action = str_replace('{pid}', $top_process['pid'], 
                                $this->config['self_healing']['actions']['high_cpu']['action']);
            exec($action);
            
            // Log the action
            $this->logSelfHealingAction('Terminated high CPU process: ' . $top_process['name']);
        }
    }
    
    private function executeDiskCleanupAction($drive_info) {
        $action = $this->config['self_healing']['actions']['low_disk_space']['action'];
        exec($action);
        
        // Log the action
        $this->logSelfHealingAction('Initiated disk cleanup for: ' . $drive_info);
    }
    
    private function logSelfHealingAction($action) {
        $log = [
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => $action
        ];
        
        $log_file = 'metrics_data/self_healing.log';
        file_put_contents($log_file, json_encode($log) . "\n", FILE_APPEND);
    }
    
    public function getAlertHistory() {
        return $this->alert_history;
    }
    
    public function clearAlertHistory() {
        $this->alert_history = [];
        $this->saveAlertHistory();
    }
} 