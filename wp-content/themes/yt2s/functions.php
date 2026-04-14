<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('after_setup_theme', static function (): void {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);
    register_nav_menus([
        'primary' => __('Primary Menu', 'yt2s'),
    ]);
});

add_action('wp_enqueue_scripts', static function (): void {
    if (!is_page_template('page-downloader.php') && !is_front_page()) {
        return;
    }

    $version = defined('YT2S_CORE_VERSION') ? YT2S_CORE_VERSION : '0.1.0';

    wp_enqueue_style('yt2s-theme', get_stylesheet_uri(), [], $version);
    wp_enqueue_style('yt2s-downloader', get_template_directory_uri() . '/assets/css/downloader.css', [], $version);
    wp_enqueue_script('tailwind-cdn', 'https://cdn.tailwindcss.com', [], null, false);

    $plugin = class_exists('YT2S_Core') && method_exists('YT2S_Core', 'instance') ? YT2S_Core::instance() : null;
    $rest_url = function_exists('rest_url') ? rest_url('yt2s/v1/fetch') : '';
    $status_base = function_exists('rest_url') ? rest_url('yt2s/v1/status/') : '';
    $engine_url = $plugin ? $plugin->get_engine_url() : '';
    $socket_url = $plugin ? $plugin->get_socket_url() : '';

    $loopback_hosts = ['127.0.0.1', 'localhost', '::1'];
    $site_host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    $socket_host = strtolower((string) wp_parse_url($socket_url, PHP_URL_HOST));
    $socket_is_loopback = in_array($socket_host, $loopback_hosts, true);
    $site_is_loopback = in_array($site_host, $loopback_hosts, true);
    $enable_live_socket = '' !== $socket_url && !($socket_is_loopback && !$site_is_loopback);

    if ($enable_live_socket) {
        wp_enqueue_script('socket-io-cdn', 'https://cdn.socket.io/4.7.5/socket.io.min.js', [], null, true);
        wp_script_add_data('socket-io-cdn', 'defer', true);
        wp_enqueue_script('yt2s-downloader', get_template_directory_uri() . '/assets/js/downloader.js', ['socket-io-cdn'], $version, true);
    } else {
        wp_enqueue_script('yt2s-downloader', get_template_directory_uri() . '/assets/js/downloader.js', [], $version, true);
    }

    wp_localize_script('yt2s-downloader', 'yt2sDownloader', [
        'restUrl' => $rest_url,
        'statusBase' => $status_base,
        'engineUrl' => $engine_url,
        'socketUrl' => $socket_url,
        'enableLiveSocket' => $enable_live_socket,
        'debug' => true,
        'nonce' => $plugin ? $plugin->get_nonce() : wp_create_nonce('yt2s_core_fetch'),
        'brand' => 'Yt2s.cc',
    ]);
});
