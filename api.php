<?php
// Prevent any errors from being output in the JSON response
error_reporting(0);
ini_set('display_errors', 0);

// Ensure no whitespace before opening PHP tag
ob_start();

// api.php - API endpoints for the monitoring system

// Allow cross-origin requests
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Include required files
require_once 'metrics_collector.php';
require_once 'config.php';
require_once 'alert_handler.php';
require_once 'data_exporter.php';

// Initialize handlers
$alert_handler = new AlertHandler();
$data_exporter = new DataExporter();

// Simple API router
$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'collect':
        // Collect metrics on demand
        $metrics = collectSystemMetrics();
        $file = saveMetrics($metrics);
        
        // Check for alerts
        $alerts = $alert_handler->checkMetrics($metrics);
        
        $response = [
            'success' => true,
            'message' => 'Metrics collected successfully',
            'file' => $file,
            'metrics' => $metrics,
            'alerts' => $alerts
        ];
        
        // Clean output buffer and send clean JSON
        ob_clean();
        echo json_encode($response, JSON_UNESCAPED_SLASHES);
        break;
        
    case 'latest':
        // Get latest metrics
        $latest_file = 'metrics_data/latest_metrics.json';
        if (file_exists($latest_file)) {
            $metrics = json_decode(file_get_contents($latest_file), true);
            if ($metrics === null && json_last_error() !== JSON_ERROR_NONE) {
                $response = [
                    'success' => false,
                    'message' => 'Error reading metrics: ' . json_last_error_msg()
                ];
            } else {
                // Check for alerts
                $alerts = $alert_handler->checkMetrics($metrics);
                
                $response = [
                    'success' => true,
                    'metrics' => $metrics,
                    'alerts' => $alerts
                ];
            }
        } else {
            $response = [
                'success' => false,
                'message' => 'No metrics available yet'
            ];
        }
        
        // Clean output buffer and send clean JSON
        ob_clean();
        echo json_encode($response, JSON_UNESCAPED_SLASHES);
        break;
        
    case 'history':
        // Get metric history based on timeframe
        $timeframe = isset($_GET['timeframe']) ? $_GET['timeframe'] : 'hourly';
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 24;
        
        try {
            $historical_data = $data_exporter->exportHistoricalData($timeframe);
            $historical_data = array_slice($historical_data, -$limit);
            
            $response = [
                'success' => true,
                'timeframe' => $timeframe,
                'data' => $historical_data
            ];
        } catch (Exception $e) {
            $response = [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
        
        // Clean output buffer and send clean JSON
        ob_clean();
        echo json_encode($response, JSON_UNESCAPED_SLASHES);
        break;
        
    case 'export':
        // Export metrics in specified format
        $format = isset($_GET['format']) ? $_GET['format'] : null;
        $timeframe = isset($_GET['timeframe']) ? $_GET['timeframe'] : 'current';
        
        try {
            if ($timeframe === 'current') {
                $latest_file = 'metrics_data/latest_metrics.json';
                if (!file_exists($latest_file)) {
                    throw new Exception('No current metrics available');
                }
                $metrics = json_decode(file_get_contents($latest_file), true);
            } else {
                $metrics = $data_exporter->exportHistoricalData($timeframe);
            }
            
            $exported_data = $data_exporter->exportMetrics($metrics, $format);
            
            // Set appropriate headers for file download
            $timestamp = date('Y-m-d_H-i-s');
            $filename = "metrics_{$timeframe}_{$timestamp}." . ($format ?: 'json');
            header('Content-Type: application/' . ($format === 'csv' ? 'csv' : 'json'));
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            
            // Clean output buffer and send data
            ob_clean();
            echo $exported_data;
        } catch (Exception $e) {
            ob_clean();
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
        break;
        
    case 'alerts':
        // Get alert history
        $response = [
            'success' => true,
            'alerts' => $alert_handler->getAlertHistory()
        ];
        
        // Clean output buffer and send clean JSON
        ob_clean();
        echo json_encode($response, JSON_UNESCAPED_SLASHES);
        break;
        
    case 'config':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Update configuration
            $input = json_decode(file_get_contents('php://input'), true);
            if ($input && saveUserConfig($input)) {
                $response = [
                    'success' => true,
                    'message' => 'Configuration updated successfully'
                ];
            } else {
                $response = [
                    'success' => false,
                    'message' => 'Error updating configuration'
                ];
            }
        } else {
            // Get current configuration
            global $config;
            $response = [
                'success' => true,
                'config' => $config
            ];
        }
        
        // Clean output buffer and send clean JSON
        ob_clean();
        echo json_encode($response, JSON_UNESCAPED_SLASHES);
        break;
        
    case 'terminate_process':
        // Terminate a process (requires process ID)
        if (!isset($_POST['pid'])) {
            $response = [
                'success' => false,
                'message' => 'Process ID required'
            ];
        } else {
            $pid = $_POST['pid'];
            exec("taskkill /F /PID $pid", $output, $return_var);
            
            $response = [
                'success' => $return_var === 0,
                'message' => $return_var === 0 ? 'Process terminated successfully' : 'Error terminating process',
                'output' => $output
            ];
        }
        
        // Clean output buffer and send clean JSON
        ob_clean();
        echo json_encode($response, JSON_UNESCAPED_SLASHES);
        break;
        
    default:
        $response = [
            'success' => false,
            'message' => 'Unknown action'
        ];
        
        // Clean output buffer and send clean JSON
        ob_clean();
        echo json_encode($response, JSON_UNESCAPED_SLASHES);
}

// End output buffering and exit
ob_end_flush();
exit;
?>
