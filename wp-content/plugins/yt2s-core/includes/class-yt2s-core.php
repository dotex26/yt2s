<?php
if (!defined('ABSPATH')) {
    exit;
}

final class YT2S_Core {
    private static ?YT2S_Core $instance = null;

    private const OPTION_ENGINE_URL = 'yt2s_core_engine_url';
    private const OPTION_API_KEY = 'yt2s_core_api_key';
    private const OPTION_SOCKET_URL = 'yt2s_core_socket_url';
    private const NONCE_ACTION = 'yt2s_core_fetch';

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function get_engine_url(): string {
        $engine_url = (string) get_option(self::OPTION_ENGINE_URL, '');

        if ('' !== $engine_url) {
            return untrailingslashit($engine_url);
        }

        if (defined('YT2S_CORE_ENGINE_URL') && is_string(YT2S_CORE_ENGINE_URL) && '' !== YT2S_CORE_ENGINE_URL) {
            return untrailingslashit(YT2S_CORE_ENGINE_URL);
        }

        return 'http://127.0.0.1:8000';
    }

    public function get_api_key(): string {
        $api_key = (string) get_option(self::OPTION_API_KEY, '');

        if ('' !== $api_key) {
            return $api_key;
        }

        if (defined('YT2S_CORE_API_KEY') && is_string(YT2S_CORE_API_KEY)) {
            return YT2S_CORE_API_KEY;
        }

        return '';
    }

    public function get_socket_url(): string {
        $socket_url = (string) get_option(self::OPTION_SOCKET_URL, '');

        if ('' !== $socket_url) {
            return untrailingslashit($socket_url);
        }

        if (defined('YT2S_CORE_SOCKET_URL') && is_string(YT2S_CORE_SOCKET_URL) && '' !== YT2S_CORE_SOCKET_URL) {
            return untrailingslashit(YT2S_CORE_SOCKET_URL);
        }

        return $this->get_engine_url();
    }

    public function get_nonce(): string {
        return wp_create_nonce(self::NONCE_ACTION);
    }

    public function verify_nonce(?string $nonce): bool {
        if (null === $nonce || '' === $nonce) {
            return false;
        }

        return (bool) wp_verify_nonce($nonce, self::NONCE_ACTION);
    }

    public function register_menu(): void {
        add_options_page(
            'Yt2s Core',
            'Yt2s Core',
            'manage_options',
            'yt2s-core',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void {
        register_setting('yt2s_core_settings', self::OPTION_ENGINE_URL, [
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ]);

        register_setting('yt2s_core_settings', self::OPTION_API_KEY, [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        register_setting('yt2s_core_settings', self::OPTION_SOCKET_URL, [
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ]);

        add_settings_section(
            'yt2s_core_connection',
            'Engine Connection',
            static function (): void {
                echo '<p>Configure the FastAPI engine endpoint and shared secret used for request proxying.</p>';
            },
            'yt2s-core'
        );

        add_settings_field(
            self::OPTION_ENGINE_URL,
            'Engine URL',
            [$this, 'render_engine_url_field'],
            'yt2s-core',
            'yt2s_core_connection'
        );

        add_settings_field(
            self::OPTION_SOCKET_URL,
            'Socket URL',
            [$this, 'render_socket_url_field'],
            'yt2s-core',
            'yt2s_core_connection'
        );

        add_settings_field(
            self::OPTION_API_KEY,
            'Shared Secret',
            [$this, 'render_api_key_field'],
            'yt2s-core',
            'yt2s_core_connection'
        );
    }

    public function render_engine_url_field(): void {
        printf(
            '<input type="url" class="regular-text" name="%s" value="%s" placeholder="https://engine.example.com" />',
            esc_attr(self::OPTION_ENGINE_URL),
            esc_attr($this->get_engine_url())
        );
    }

    public function render_socket_url_field(): void {
        printf(
            '<input type="url" class="regular-text" name="%s" value="%s" placeholder="https://engine.example.com" />',
            esc_attr(self::OPTION_SOCKET_URL),
            esc_attr($this->get_socket_url())
        );
    }

    public function render_api_key_field(): void {
        printf(
            '<input type="password" class="regular-text" name="%s" value="%s" autocomplete="off" placeholder="Enter shared secret" />',
            esc_attr(self::OPTION_API_KEY),
            esc_attr($this->get_api_key())
        );
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        ?>
        <div class="wrap">
            <h1>Yt2s Core</h1>
            <p>Proxy settings for the WordPress-to-engine bridge.</p>
            <form method="post" action="options.php">
                <?php
                settings_fields('yt2s_core_settings');
                do_settings_sections('yt2s-core');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function register_routes(): void {
        $controller = new YT2S_Rest_Controller($this);
        $controller->register_routes();
    }
}
