<?php
/**
 * Plugin Name: WP Performance Logger
 * Description: Logs all MySQL queries site-wide and provides detailed reports.
 * Author: Terrific Objects
 * Version: 0.9.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Query_Logger {
    private static $log_file;
    private static $start_time;
    private static $run_time_limit;
    private static $is_logging_enabled;
    private static $query_stats = [];
    private static $user_id;

    public static function init() {
        self::$log_file = WP_CONTENT_DIR . '/uploads/query-log.txt';
        self::$is_logging_enabled = get_option('query_logger_enabled', false);
        self::$run_time_limit = get_option('query_logger_time_limit', 60);
        self::$user_id = get_current_user_id();

        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('admin_post_query_logger_start', [__CLASS__, 'enable_logging']);
        add_action('admin_post_query_logger_stop', [__CLASS__, 'disable_logging']);
        add_action('admin_post_query_logger_download_log', [__CLASS__, 'download_log']);
        add_action('admin_post_query_logger_delete_log', [__CLASS__, 'delete_log']);
        add_action('query_logger_cron_event', [__CLASS__, 'log_queries']);

        if (self::$is_logging_enabled) {
            add_filter('query', [__CLASS__, 'log_query'], 100, 1);
        }

        if (self::$is_logging_enabled && !wp_next_scheduled('query_logger_cron_event')) {
            wp_schedule_event(time(), 'every_second', 'query_logger_cron_event');
        }
    }

    public static function enable_logging() {
        if (!isset($_POST['query_logger_time_limit']) || !is_numeric($_POST['query_logger_time_limit'])) {
            wp_die("Invalid time limit.");
        }

        $time_limit = intval($_POST['query_logger_time_limit']);
        update_option('query_logger_enabled', true);
        update_option('query_logger_time_limit', $time_limit);
        $start_time = microtime(true);
        update_option('query_logger_start_time', $start_time);

        self::log_start_info();

        add_filter('query', [__CLASS__, 'log_query'], 100, 1);

        if (!wp_next_scheduled('query_logger_cron_event')) {
            wp_schedule_event(time(), 'every_second', 'query_logger_cron_event');
        }

        wp_redirect(admin_url('tools.php?page=query-logger&log=started'));
        exit;
    }

    public static function disable_logging() {
        update_option('query_logger_enabled', false);

        $timestamp = wp_next_scheduled('query_logger_cron_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'query_logger_cron_event');
        }

        self::log_end_info();

        wp_redirect(admin_url('tools.php?page=query-logger&log=stopped'));
        exit;
    }

    public static function log_queries() {
        if (!get_option('query_logger_enabled')) {
            return;
        }

        $start_time = get_option('query_logger_start_time');
        $run_time_limit = get_option('query_logger_time_limit', 60);

        $current_time = microtime(true);
        if (($current_time - $start_time) >= $run_time_limit) {
            self::disable_logging();
            return;
        }
    }

    public static function log_query($query) {
        $start = microtime(true);
        $duration = microtime(true) - $start;

        $entry = sprintf("[%s] (%.6f sec) %s\n", self::get_mst_time(), $duration, $query);
        file_put_contents(self::$log_file, $entry, FILE_APPEND | LOCK_EX);

        self::$query_stats[] = [
            'query' => $query,
            'time'  => $duration,
        ];

        return $query;
    }

    public static function log_start_info() {
        $user_info = get_user_by('id', self::$user_id);
        $username = $user_info ? $user_info->user_login : 'Unknown User';

        $log  = "\n===== Logging Started =====\n";
        $log .= "Start Time: " . self::get_mst_time() . "\n";
        $log .= "Initiated By: " . $username . " (User ID: " . self::$user_id . ")\n";
        $log .= "Logging Duration: " . self::$run_time_limit . " seconds\n\n";

        file_put_contents(self::$log_file, $log, FILE_APPEND | LOCK_EX);
    }

    public static function log_end_info() {
        if (empty(self::$query_stats)) {
            return;
        }

        $end_time = self::get_mst_time();
        $total_queries = count(self::$query_stats);
        $execution_time = microtime(true) - get_option('query_logger_start_time');
        $memory_usage = memory_get_peak_usage(true) / 1024 / 1024;

        usort(self::$query_stats, function ($a, $b) {
            return $b['time'] <=> $a['time'];
        });

        $top_5_longest = array_slice(self::$query_stats, 0, 5);
        $query_counts = [];

        foreach (self::$query_stats as $query_item) {
            $normalized = preg_replace('/\s+/', ' ', trim($query_item['query']));
            if (!isset($query_counts[$normalized])) {
                $query_counts[$normalized] = 0;
            }
            $query_counts[$normalized]++;
        }

        arsort($query_counts);
        $top_10_repeated = array_slice($query_counts, 0, 10, true);

        $report = "\n\n===== Query Log Report =====\n";
        $report .= "\nTop 5 Longest Queries:\n";
        foreach ($top_5_longest as $item) {
            $report .= sprintf("(%.6f sec) %s\n", $item['time'], $item['query']);
        }

        $report .= "\nTop 10 Most Repeated Queries:\n";
        foreach ($top_10_repeated as $query_str => $count) {
            $report .= sprintf("(%dx) %s\n", $count, $query_str);
        }

        $report .= "\n===== PHP Resource Usage =====\n";
        $report .= "Peak Memory Usage: " . number_format($memory_usage, 2) . " MB\n";
        $report .= "Execution Time: " . number_format($execution_time, 4) . " seconds\n";

        $report .= "\n===== Logging Ended =====\n";
        $report .= "End Time: " . $end_time . "\n";
        $report .= "Total Queries Logged: " . $total_queries . "\n";

        file_put_contents(self::$log_file, $report, FILE_APPEND | LOCK_EX);
    }
    
    public static function download_log() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to download this log.'));
        }

        if (!file_exists(self::$log_file)) {
            wp_die(__('Log file not found.'));
        }

        header('Content-Description: File Transfer');
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="query-log.txt"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize(self::$log_file));

        readfile(self::$log_file);
        exit;
    }
    
    public static function delete_log() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to delete this log.'));
        }

        check_admin_referer('query_logger_delete_log');
    
        if (file_exists(self::$log_file)) {
            unlink(self::$log_file);
        }

        wp_redirect(admin_url('tools.php?page=query-logger&log=deleted'));
        exit;
    }

    public static function get_mst_time() {
        $date = new DateTime("now", new DateTimeZone("America/Denver"));
        return $date->format("Y-m-d H:i:s T");
    }

    public static function add_admin_menu() {
        add_submenu_page('tools.php', 'Query Logger', 'Query Logger', 'manage_options', 'query-logger', [__CLASS__, 'admin_page']);
    }

    public static function admin_page() {
        $log_exists = file_exists(self::$log_file);
        ?>
        <div class="wrap">
            <h1>Query Logger</h1>
            <form method="post" action="<?php echo admin_url('admin-post.php?action=query_logger_start'); ?>">
                <table class="form-table">
                    <tr>
                        <th>Logging Duration (Seconds)</th>
                        <td>
                            <input type="number" name="query_logger_time_limit" value="<?php echo esc_attr(get_option('query_logger_time_limit', 60)); ?>" min="5">
                        </td>
                    </tr>
                </table>
                <button type="submit" class="button button-primary">Start Logging</button>
            </form>
            <form method="post" action="<?php echo admin_url('admin-post.php?action=query_logger_stop'); ?>">
                <button type="submit" class="button button-secondary">Stop Logging</button>
            </form>
            <?php if ($log_exists): ?>
                <form method="post" action="<?php echo admin_url('admin-post.php?action=query_logger_download_log'); ?>">
                    <button type="submit" class="button">Download Log</button>
                </form>
                <form method="post" action="<?php echo admin_url('admin-post.php?action=query_logger_delete_log'); ?>">
                    <?php wp_nonce_field('query_logger_delete_log'); ?>
                    <button type="submit" class="button button-danger">Delete Log</button>
                </form>
            <?php else: ?>
                <p><em>No log file available.</em></p>
            <?php endif; ?>
        </div>
        <?php
    }
}

WP_Query_Logger::init();

add_filter('cron_schedules', function($schedules) {
    $schedules['every_second'] = ['interval' => 1, 'display' => __('Every Second')];
    return $schedules;
});
