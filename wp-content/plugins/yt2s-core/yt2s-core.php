<?php
/**
 * Plugin Name: Yt2s Core
 * Description: Handles downloader requests directly in WordPress using PHP cron processing.
 * Version: 0.2.1
 * Author: Copilot
 */

if (!defined('ABSPATH')) {
    exit;
}

define('YT2S_CORE_VERSION', '0.2.1');
define('YT2S_CORE_FILE', __FILE__);
define('YT2S_CORE_PATH', plugin_dir_path(__FILE__));
define('YT2S_CORE_URL', plugin_dir_url(__FILE__));

require_once YT2S_CORE_PATH . 'includes/class-yt2s-core.php';
require_once YT2S_CORE_PATH . 'includes/class-rest-controller.php';

add_action('plugins_loaded', static function () {
    YT2S_Core::instance();
});

register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('yt2s_core_cleanup_artifacts');
    wp_clear_scheduled_hook('yt2s_core_process_job');
});
