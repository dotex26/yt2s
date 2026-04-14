<?php
if (!defined('ABSPATH')) {
    exit;
}

final class YT2S_Rest_Controller {
    private YT2S_Core $plugin;
    private const TRANSIENT_PREFIX = 'yt2s_demo_job_';

    public function __construct(YT2S_Core $plugin) {
        $this->plugin = $plugin;
    }

    public function register_routes(): void {
        register_rest_route('yt2s/v1', '/ping', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'handle_ping'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('yt2s/v1', '/demo-artifact/(?P<job_id>[A-Za-z0-9_-]+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'handle_demo_artifact'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('yt2s/v1', '/fetch', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_fetch'],
            'permission_callback' => [$this, 'permission_callback'],
            'args' => [
                'source_url' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'job_id' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'format_id' => [
                    'required' => false,
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route('yt2s/v1', '/status/(?P<job_id>[A-Za-z0-9_-]+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'handle_status'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);
    }

    public function handle_ping(): WP_REST_Response {
        return new WP_REST_Response([
            'ok' => true,
            'service' => 'yt2s-core',
            'version' => YT2S_CORE_VERSION,
        ], 200);
    }

    public function handle_demo_artifact(WP_REST_Request $request): WP_REST_Response {
        $job_id = sanitize_text_field((string) $request->get_param('job_id'));
        $job = get_transient(self::TRANSIENT_PREFIX . $job_id);

        if (!is_array($job) || !isset($job['status']) || 'completed' !== $job['status']) {
            return new WP_REST_Response('Demo artifact is not ready yet.', 409, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        $label = isset($job['selected_format_label']) ? (string) $job['selected_format_label'] : 'N/A';
        $source = isset($job['source_url']) ? (string) $job['source_url'] : '';
        $content = "Yt2s Demo Artifact\n"
            . "Job ID: {$job_id}\n"
            . "Selected Format: {$label}\n"
            . "Source URL: {$source}\n"
            . "\nThis is a demo output. Configure a live engine endpoint for real files.\n";

        return new WP_REST_Response($content, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="yt2s-demo-' . $job_id . '.txt"',
        ]);
    }

    public function permission_callback(WP_REST_Request $request): bool|WP_Error {
        return true;
    }

    public function handle_fetch(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $payload = [
            'source_url' => esc_url_raw((string) $request->get_param('source_url')),
            'job_id' => sanitize_text_field((string) $request->get_param('job_id')),
            'format_id' => sanitize_text_field((string) $request->get_param('format_id')),
        ];

        if ('' === $payload['source_url'] || !wp_http_validate_url($payload['source_url'])) {
            return new WP_Error('yt2s_core_invalid_url', 'Please provide a valid URL.', ['status' => 400]);
        }

        $engine_response = $this->forward_to_engine('/media/fetch', $payload, 'POST');

        if (is_wp_error($engine_response)) {
            if ($this->is_demo_fallback_enabled()) {
                return new WP_REST_Response($this->build_demo_fetch_response($payload), 200);
            }

            return $engine_response;
        }

        return new WP_REST_Response($engine_response, 200);
    }

    public function handle_status(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $job_id = sanitize_text_field((string) $request->get_param('job_id'));

        if ('' === $job_id) {
            return new WP_Error('yt2s_core_missing_job', 'Missing job id.', ['status' => 400]);
        }

        $engine_response = $this->forward_to_engine('/media/jobs/' . rawurlencode($job_id), [], 'GET');

        if (is_wp_error($engine_response)) {
            if ($this->is_demo_fallback_enabled()) {
                return new WP_REST_Response($this->build_demo_status_response($job_id), 200);
            }

            return $engine_response;
        }

        return new WP_REST_Response($engine_response, 200);
    }

    private function is_demo_fallback_enabled(): bool {
        if (defined('YT2S_CORE_ENABLE_DEMO_FALLBACK')) {
            return (bool) YT2S_CORE_ENABLE_DEMO_FALLBACK;
        }

        return true;
    }

    private function demo_formats(): array {
        return [
            [
                'id' => 'mp4-4k',
                'label' => 'MP4 4K',
                'kind' => 'video',
                'container' => 'mp4',
                'note' => 'High-resolution demo profile.',
            ],
            [
                'id' => 'mp4-1080',
                'label' => 'MP4 1080p',
                'kind' => 'video',
                'container' => 'mp4',
                'note' => 'Balanced quality and size.',
            ],
            [
                'id' => 'mp4-720',
                'label' => 'MP4 720p',
                'kind' => 'video',
                'container' => 'mp4',
                'note' => 'Fastest video profile.',
            ],
            [
                'id' => 'mp3-320',
                'label' => 'MP3 320kbps',
                'kind' => 'audio',
                'container' => 'mp3',
                'bitrate' => '320kbps',
                'note' => 'High-bitrate audio profile.',
            ],
            [
                'id' => 'mp3-128',
                'label' => 'MP3 128kbps',
                'kind' => 'audio',
                'container' => 'mp3',
                'bitrate' => '128kbps',
                'note' => 'Compact audio profile.',
            ],
        ];
    }

    private function build_demo_fetch_response(array $payload): array {
        $job_id = '' !== $payload['job_id'] ? $payload['job_id'] : wp_generate_password(12, false, false);
        $formats = $this->demo_formats();

        $job = [
            'job_id' => $job_id,
            'source_url' => $payload['source_url'],
            'status' => 'ready',
            'progress' => 20,
            'message' => 'Demo mode active. Configure engine URL in Yt2s Core settings for live processing.',
            'selected_format_id' => null,
            'selected_format_label' => null,
            'formats' => $formats,
            'result_url' => null,
            'mux_command' => null,
            'poll_count' => 0,
        ];

        if ('' !== $payload['format_id']) {
            $selected = null;
            foreach ($formats as $format) {
                if ($format['id'] === $payload['format_id']) {
                    $selected = $format;
                    break;
                }
            }

            if (is_array($selected)) {
                $job['status'] = 'processing';
                $job['progress'] = 35;
                $job['message'] = sprintf('Demo mode: processing %s. Please configure a live engine endpoint.', $selected['label']);
                $job['selected_format_id'] = $selected['id'];
                $job['selected_format_label'] = $selected['label'];
            }
        }

        set_transient(self::TRANSIENT_PREFIX . $job_id, $job, 10 * MINUTE_IN_SECONDS);

        unset($job['poll_count']);
        return $job;
    }

    private function build_demo_status_response(string $job_id): array {
        $job = get_transient(self::TRANSIENT_PREFIX . $job_id);

        if (!is_array($job)) {
            return [
                'job_id' => $job_id,
                'status' => 'failed',
                'progress' => 0,
                'message' => 'Demo job expired. Re-run analysis.',
                'selected_format_id' => null,
                'selected_format_label' => null,
                'formats' => $this->demo_formats(),
                'result_url' => null,
                'mux_command' => null,
            ];
        }

        $poll_count = isset($job['poll_count']) ? (int) $job['poll_count'] : 0;
        $poll_count++;
        $job['poll_count'] = $poll_count;

        if ('processing' === $job['status']) {
            $next_progress = min(95, (int) $job['progress'] + 25);
            $job['progress'] = $next_progress;

            if ($poll_count >= 3) {
                $job['status'] = 'completed';
                $job['progress'] = 100;
                $job['message'] = 'Demo complete. Set a real engine URL to generate downloadable files.';
                $job['result_url'] = rest_url('yt2s/v1/demo-artifact/' . rawurlencode($job_id));
            }
        }

        set_transient(self::TRANSIENT_PREFIX . $job_id, $job, 10 * MINUTE_IN_SECONDS);

        unset($job['poll_count']);
        return $job;
    }

    private function forward_to_engine(string $path, array $payload, string $method): array|WP_Error {
        $url = $this->plugin->get_engine_url() . $path;
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $api_key = $this->plugin->get_api_key();

        if ('' !== $api_key) {
            $headers['X-Yt2s-Api-Key'] = $api_key;
        }

        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => $headers,
        ];

        if ('GET' !== $method) {
            $args['body'] = wp_json_encode($payload);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return new WP_Error('yt2s_core_engine_error', $response->get_error_message(), ['status' => 502]);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return new WP_Error('yt2s_core_bad_response', 'The engine returned an invalid response.', ['status' => 502]);
        }

        if ($status_code >= 400) {
            $message = isset($decoded['detail']) && is_string($decoded['detail']) ? $decoded['detail'] : 'The engine rejected the request.';
            return new WP_Error('yt2s_core_engine_rejected', $message, ['status' => $status_code]);
        }

        return $decoded;
    }
}
