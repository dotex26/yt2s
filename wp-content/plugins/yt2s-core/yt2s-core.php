<?php
/**
 * Plugin Name: Yt2s Core
 * Description: Proxies downloader requests from WordPress to the Yt2s engine.
 * Version: 0.1.3
 * Author: Copilot
 */

if (!defined('ABSPATH')) {
    exit;
}

define('YT2S_CORE_VERSION', '0.1.3');
define('YT2S_CORE_FILE', __FILE__);
define('YT2S_CORE_PATH', plugin_dir_path(__FILE__));
define('YT2S_CORE_URL', plugin_dir_url(__FILE__));

require_once YT2S_CORE_PATH . 'includes/class-yt2s-core.php';
require_once YT2S_CORE_PATH . 'includes/class-rest-controller.php';

add_action('plugins_loaded', static function () {
    YT2S_Core::instance();
});
