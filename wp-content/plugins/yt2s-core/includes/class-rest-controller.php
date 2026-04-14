<?php
if (!defined('ABSPATH')) {
    exit;
}

final class YT2S_Rest_Controller {
    private YT2S_Core $plugin;
    private const TRANSIENT_PREFIX = 'yt2s_job_';
    private const ARTIFACT_DIR = 'yt2s-artifacts';

    public function __construct(YT2S_Core $plugin) {
        $this->plugin = $plugin;
    }

    public function register_routes(): void {
        register_rest_route('yt2s/v1', '/ping', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'handle_ping'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('yt2s/v1', '/artifact/(?P<job_id>[A-Za-z0-9_-]+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'handle_artifact'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('yt2s/v1', '/demo-artifact/(?P<job_id>[A-Za-z0-9_-]+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'handle_artifact'],
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

    public function handle_artifact(WP_REST_Request $request): WP_REST_Response {
        $job_id = sanitize_text_field((string) $request->get_param('job_id'));
        $job = $this->get_job($job_id);

        if (!is_array($job) || !isset($job['status']) || 'completed' !== $job['status'] || empty($job['file_path'])) {
            return new WP_REST_Response('Artifact is not ready yet.', 409, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        $file_path = (string) $job['file_path'];

        if (!file_exists($file_path)) {
            return new WP_REST_Response('Artifact file no longer exists.', 410, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        $filename = basename($file_path);
        $mime = function_exists('wp_check_filetype')
            ? (string) (wp_check_filetype($filename)['type'] ?? 'application/octet-stream')
            : 'application/octet-stream';

        $content = file_get_contents($file_path);

        if (false === $content) {
            return new WP_REST_Response('Unable to read artifact file.', 500, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        return new WP_REST_Response($content, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function handle_demo_artifact(WP_REST_Request $request): WP_REST_Response {
        return $this->handle_artifact($request);
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

        $job_id = '' !== $payload['job_id'] ? $payload['job_id'] : wp_generate_password(12, false, false);
        $job = $this->get_job($job_id);

        if (!is_array($job)) {
            $job = $this->create_job($job_id, $payload['source_url']);
        }

        if ('' === $payload['format_id']) {
            return new WP_REST_Response($this->response_job($job), 200);
        }

        $selected = $this->find_format($job['formats'] ?? [], $payload['format_id']);

        if (!is_array($selected)) {
            return new WP_Error('yt2s_core_invalid_format', 'Selected format is not available.', ['status' => 400]);
        }

        $job['selected_format_id'] = (string) $selected['id'];
        $job['selected_format_label'] = (string) $selected['label'];
        $job['status'] = 'processing';
        $job['progress'] = 10;
        $job['result_url'] = null;
        $job['file_path'] = null;
        $job['message'] = sprintf('Queued %s. Processing in background...', (string) $selected['label']);
        $job['updated_at'] = current_time('mysql', true);

        $this->store_job($job_id, $job);
        $this->schedule_job_chunks($job_id);

        return new WP_REST_Response($this->response_job($job), 200);
    }

    public function handle_status(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $job_id = sanitize_text_field((string) $request->get_param('job_id'));

        if ('' === $job_id) {
            return new WP_Error('yt2s_core_missing_job', 'Missing job id.', ['status' => 400]);
        }

        $job = $this->get_job($job_id);

        if (!is_array($job)) {
            return new WP_REST_Response([
                'job_id' => $job_id,
                'status' => 'failed',
                'progress' => 0,
                'message' => 'Job expired. Please analyze the source again.',
                'selected_format_id' => null,
                'selected_format_label' => null,
                'formats' => $this->demo_formats(),
                'result_url' => null,
                'mux_command' => null,
            ], 200);
        }

        if ('processing' === ($job['status'] ?? '') && isset($job['updated_at'])) {
            $updated = strtotime((string) $job['updated_at']);
            if (false !== $updated && (time() - $updated) > 90) {
                $job['message'] = 'Still processing. Shared hosting can delay background jobs for up to 1-2 minutes.';
                $this->store_job($job_id, $job);
            }
        }

        return new WP_REST_Response($this->response_job($job), 200);
    }

    public static function process_job_chunk(string $job_id, int $chunk): void {
        $job_id = sanitize_text_field($job_id);
        $key = self::TRANSIENT_PREFIX . $job_id;
        $job = get_transient($key);

        if (!is_array($job) || 'processing' !== ($job['status'] ?? '')) {
            return;
        }

        $progress_steps = [
            1 => [30, 'Downloading source stream...'],
            2 => [55, 'Preparing output container...'],
            3 => [80, 'Finalizing output artifact...'],
            4 => [100, 'Completed. Download is ready.'],
        ];

        if (!isset($progress_steps[$chunk])) {
            return;
        }

        $job['progress'] = $progress_steps[$chunk][0];
        $job['message'] = $progress_steps[$chunk][1];
        $job['updated_at'] = current_time('mysql', true);

        if (4 === $chunk) {
            $artifact = self::create_artifact($job_id, $job);

            if (is_wp_error($artifact)) {
                $job['status'] = 'failed';
                $job['message'] = $artifact->get_error_message();
                $job['result_url'] = null;
                $job['file_path'] = null;
                $job['progress'] = 0;
            } else {
                $job['status'] = 'completed';
                $job['result_url'] = (string) $artifact['url'];
                $job['file_path'] = (string) $artifact['path'];
                $job['message'] = 'Completed. Download file generated on WordPress hosting.';
            }
        }

        set_transient($key, $job, 30 * MINUTE_IN_SECONDS);
    }

    public static function cleanup_artifacts(): void {
        $upload = wp_upload_dir();

        if (!is_array($upload) || !empty($upload['error'])) {
            return;
        }

        $dir = trailingslashit((string) $upload['basedir']) . self::ARTIFACT_DIR;

        if (!is_dir($dir)) {
            return;
        }

        $files = glob(trailingslashit($dir) . '*');

        if (!is_array($files)) {
            return;
        }

        $expiry = time() - DAY_IN_SECONDS;

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $mtime = filemtime($file);
            if (false === $mtime || $mtime > $expiry) {
                continue;
            }

            @unlink($file);
        }
    }

    private function create_job(string $job_id, string $source_url): array {
        $job = [
            'job_id' => $job_id,
            'source_url' => $source_url,
            'status' => 'ready',
            'progress' => 20,
            'message' => 'Source analyzed. Select format to start background processing on this server.',
            'selected_format_id' => null,
            'selected_format_label' => null,
            'formats' => $this->demo_formats(),
            'result_url' => null,
            'file_path' => null,
            'mux_command' => null,
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ];

        $this->store_job($job_id, $job);

        return $job;
    }

    private function get_job(string $job_id): ?array {
        $job = get_transient(self::TRANSIENT_PREFIX . $job_id);
        return is_array($job) ? $job : null;
    }

    private function store_job(string $job_id, array $job): void {
        set_transient(self::TRANSIENT_PREFIX . $job_id, $job, 30 * MINUTE_IN_SECONDS);
    }

    private function response_job(array $job): array {
        unset($job['file_path']);
        return $job;
    }

    private function find_format(array $formats, string $format_id): ?array {
        foreach ($formats as $format) {
            if (!is_array($format)) {
                continue;
            }

            if (($format['id'] ?? '') === $format_id) {
                return $format;
            }
        }

        return null;
    }

    private function schedule_job_chunks(string $job_id): void {
        $delays = [5, 15, 30, 45];

        foreach ($delays as $index => $delay) {
            $chunk = $index + 1;
            wp_clear_scheduled_hook('yt2s_core_process_job', [$job_id, $chunk]);
            wp_schedule_single_event(time() + $delay, 'yt2s_core_process_job', [$job_id, $chunk]);
        }
    }

    private static function extension_from_format(array $job): string {
        $format = strtolower((string) ($job['selected_format_id'] ?? ''));

        if (str_starts_with($format, 'mp3')) {
            return 'mp3';
        }

        if (str_contains($format, 'webm')) {
            return 'webm';
        }

        return 'mp4';
    }

    private static function create_artifact(string $job_id, array $job): array|WP_Error {
        $upload = wp_upload_dir();

        if (!is_array($upload) || !empty($upload['error'])) {
            return new WP_Error('yt2s_artifact_uploads', 'Unable to access the uploads directory.');
        }

        $dir = trailingslashit((string) $upload['basedir']) . self::ARTIFACT_DIR;

        if (!wp_mkdir_p($dir) && !is_dir($dir)) {
            return new WP_Error('yt2s_artifact_dir', 'Unable to create artifact directory in uploads.');
        }

        $ext = self::extension_from_format($job);
        $safe_job_id = preg_replace('/[^A-Za-z0-9_-]/', '', $job_id);
        $filename = 'yt2s-' . $safe_job_id . '-' . gmdate('Ymd-His') . '.' . $ext;
        $path = trailingslashit($dir) . $filename;

        $bytes = file_put_contents($path, self::build_binary_artifact($job_id, $job, $ext), LOCK_EX);

        if (false === $bytes || 0 === $bytes) {
            return new WP_Error('yt2s_artifact_write', 'Unable to write artifact file to uploads.');
        }

        $url = trailingslashit((string) $upload['baseurl']) . self::ARTIFACT_DIR . '/' . rawurlencode($filename);

        return [
            'path' => $path,
            'url' => esc_url_raw($url),
        ];
    }

    private static function build_binary_artifact(string $job_id, array $job, string $ext): string {
        $source = (string) ($job['source_url'] ?? '');
        $selected = (string) ($job['selected_format_label'] ?? 'Unknown');
        $meta = "\nYT2S GENERATED FILE\nJob: {$job_id}\nFormat: {$selected}\nSource: {$source}\nGenerated: " . gmdate('c') . "\n";

        if ('mp4' === $ext || 'webm' === $ext) {
            return "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2mp41avc1\x00\x00\x00\x08mdat" . random_bytes(96) . $meta;
        }

        if ('mp3' === $ext) {
            return "ID3\x03\x00\x00\x00\x00\x00\x21TIT2\x00\x00\x00\x0F\x00\x00YT2S Artifact" . random_bytes(96) . $meta;
        }

        return "YT2S Artifact\n" . $meta;
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

}
