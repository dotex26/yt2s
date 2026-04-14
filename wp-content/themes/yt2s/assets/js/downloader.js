(() => {
    const config = window.yt2sDownloader || {};
    const form = document.getElementById('yt2s-form');
    const sourceInput = document.getElementById('source-url');
    const skeleton = document.getElementById('skeleton');
    const formatsGrid = document.getElementById('formats-grid');
    const statusText = document.getElementById('status-text');
    const statusPercent = document.getElementById('status-percent');
    const progressBar = document.getElementById('progress-bar');
    const jobMeta = document.getElementById('job-meta');

    let currentJobId = '';
    let currentSocket = null;
    let currentFormats = [];

    const isLoopbackHost = (host) => ['127.0.0.1', 'localhost', '::1'].includes((host || '').toLowerCase());

    const canUseLiveSocket = () => {
        if (config.enableLiveSocket === false) {
            return false;
        }

        if (!window.io || !config.socketUrl) {
            return false;
        }

        try {
            const socketUrl = new URL(config.socketUrl, window.location.origin);
            const pageHost = (window.location.hostname || '').toLowerCase();
            if (isLoopbackHost(socketUrl.hostname) && !isLoopbackHost(pageHost)) {
                return false;
            }
        } catch (error) {
            return false;
        }

        return true;
    };

    const debugLog = (level, message, extra = null) => {
        if (!config.debug) {
            return;
        }

        if (extra !== null) {
            console[level](`[yt2s] ${message}`, extra);
        } else {
            console[level](`[yt2s] ${message}`);
        }
    };

    const normalizeDownloadUrl = (value) => {
        if (!value || typeof value !== 'string') {
            return '';
        }

        if (/^https?:\/\//i.test(value)) {
            return value;
        }

        if (value.startsWith('/wp-json/')) {
            return `${window.location.origin}${value}`;
        }

        if (value.startsWith('/') && config.engineUrl) {
            return `${String(config.engineUrl).replace(/\/$/, '')}${value}`;
        }

        return value;
    };

    const isLikelyWatchPageUrl = (value) => {
        if (!value || typeof value !== 'string') {
            return false;
        }

        try {
            const parsed = new URL(value);
            const host = (parsed.hostname || '').toLowerCase();
            const path = (parsed.pathname || '').toLowerCase();
            const hasWatchParam = parsed.searchParams.has('v') || parsed.searchParams.has('list');
            const mediaExtPattern = /\.(mp4|webm|mp3|m4a|aac|wav|ogg|mov|mkv)(\?|$)/i;

            if (mediaExtPattern.test(value)) {
                return false;
            }

            if (host.includes('youtube.com') || host.includes('youtu.be')) {
                return true;
            }

            if (path.includes('/watch') || path.includes('/video') || hasWatchParam) {
                return true;
            }
        } catch (error) {
            return false;
        }

        return false;
    };

    const setLoading = (loading) => {
        skeleton.classList.toggle('hidden', !loading);
        form.querySelector('button[type="submit"]').disabled = loading;
        form.querySelector('button[type="submit"]').classList.toggle('opacity-60', loading);
    };

    const setStatus = (percent, message) => {
        statusPercent.textContent = `${Math.round(percent)}%`;
        statusText.textContent = message;
        progressBar.style.width = `${Math.max(0, Math.min(100, percent))}%`;
    };

    const renderJobMeta = (job) => {
        const downloadUrl = normalizeDownloadUrl(job.result_url || '');
        const isDemoMode = config.socketUrl && ['127.0.0.1', 'localhost'].includes((new URL(config.socketUrl, window.location.origin)).hostname);
        const demoModeLabel = isDemoMode ? '<p class="mt-3 inline-block rounded-full border border-amber-500/30 bg-amber-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-amber-300">Demo Mode</p>' : '';
        const downloadBlock = job.status === 'completed'
            ? (downloadUrl
                ? `<a href="${downloadUrl}" class="mt-4 inline-flex items-center rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300" target="_blank" rel="noopener">Download File</a>`
                : '<p class="mt-4 text-xs text-amber-300">No download URL was returned by the engine.</p>')
            : '';

        jobMeta.innerHTML = `
            <p class="font-semibold text-white">Job details</p>
            ${demoModeLabel}
            <div class="mt-3 space-y-2 text-slate-300">
                <p><span class="text-slate-500">Job ID:</span> ${job.job_id || 'Pending'}</p>
                <p><span class="text-slate-500">Status:</span> ${job.status || 'idle'}</p>
                <p><span class="text-slate-500">Selected format:</span> ${job.selected_format_label || 'None'}</p>
            </div>
            ${downloadBlock}
        `;
    };

    const disconnectSocket = () => {
        if (currentSocket) {
            currentSocket.disconnect();
            currentSocket = null;
        }
    };

    const attachSocket = (jobId) => {
        disconnectSocket();

        if (!canUseLiveSocket()) {
            debugLog('warn', 'Live socket disabled. Using polling mode only.', {
                socketUrl: config.socketUrl,
                pageHost: window.location.hostname,
                enableLiveSocket: config.enableLiveSocket,
            });
            return;
        }

        currentSocket = window.io(config.socketUrl, {
            transports: ['websocket', 'polling'],
            reconnection: false,
            timeout: 3000,
        });

        currentSocket.on('connect', () => {
            currentSocket.emit('subscribe', { job_id: jobId });
        });

        currentSocket.on('progress', (payload) => {
            if (!payload || payload.job_id !== jobId) {
                return;
            }

            setStatus(payload.progress || 0, payload.message || 'Processing...');
            renderJobMeta(payload);
        });

        currentSocket.on('job_complete', (payload) => {
            if (!payload || payload.job_id !== jobId) {
                return;
            }

            setStatus(100, payload.message || 'Processing complete.');
            renderJobMeta(payload);
        });

        currentSocket.on('connect_error', (error) => {
            debugLog('error', 'Socket connection failed. Falling back to polling.', error && error.message ? error.message : error);
            statusText.textContent = 'Live progress is unavailable, falling back to polling.';
            disconnectSocket();
        });
    };

    const pollJob = async (jobId, attempt = 0) => {
        if (!config.statusBase) {
            return;
        }

        try {
            const response = await fetch(`${config.statusBase}${jobId}`, {
                headers: {
                    'X-Yt2s-Nonce': config.nonce,
                },
            });

            if (!response.ok) {
                debugLog('warn', 'Polling returned non-OK response.', { status: response.status, attempt });
                if (attempt < 25) {
                    window.setTimeout(() => pollJob(jobId, attempt + 1), Math.min(5000, 1200 + (attempt * 200)));
                } else {
                    statusText.textContent = 'Progress check timed out. Please try again.';
                }
                return;
            }

            const payload = await response.json();
            setStatus(payload.progress || 0, payload.message || 'Processing...');
            renderJobMeta(payload);

            if (payload.status !== 'completed' && payload.status !== 'failed') {
                window.setTimeout(() => pollJob(jobId, 0), 1400);
            }
        } catch (error) {
            debugLog('error', 'Polling request failed.', error && error.message ? error.message : error);
            if (attempt < 25) {
                window.setTimeout(() => pollJob(jobId, attempt + 1), Math.min(5000, 1400 + (attempt * 200)));
            } else {
                statusText.textContent = 'Network issue while checking progress. Please retry.';
            }
        }
    };

    const renderFormats = (formats, jobId, sourceUrl) => {
        currentFormats = Array.isArray(formats) ? formats : [];
        formatsGrid.innerHTML = '';

        if (!currentFormats.length) {
            formatsGrid.innerHTML = '<div class="rounded-2xl border border-white/10 bg-white/5 p-4 text-sm text-slate-400">No formats were returned for this source.</div>';
            return;
        }

        currentFormats.forEach((format) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'format-card rounded-2xl p-4 text-left';
            button.dataset.formatId = format.id;
            button.innerHTML = `
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-white">${format.label}</p>
                        <p class="mt-1 text-xs uppercase tracking-[0.22em] text-slate-500">${format.kind}</p>
                    </div>
                    <span class="rounded-full border border-cyan-300/20 bg-cyan-300/10 px-3 py-1 text-xs font-semibold text-cyan-200">Select</span>
                </div>
                <div class="mt-4 space-y-1 text-sm text-slate-300">
                    <p>${format.container || 'mp4'}</p>
                    <p>${format.note || 'Ready for processing'}</p>
                </div>
            `;

            button.addEventListener('click', async () => {
                document.querySelectorAll('.format-card').forEach((item) => item.classList.remove('is-active'));
                button.classList.add('is-active');
                setLoading(true);
                setStatus(8, `Preparing ${format.label}... Please wait.`);

                try {
                    const response = await fetch(config.restUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Yt2s-Nonce': config.nonce,
                        },
                        body: JSON.stringify({
                            source_url: sourceUrl,
                            job_id: jobId,
                            format_id: format.id,
                        }),
                    });

                    const payload = await response.json();

                    if (!response.ok) {
                        throw new Error(payload.message || payload.detail || 'Processing request failed.');
                    }

                    currentJobId = payload.job_id || jobId;
                    renderJobMeta(payload);
                    setStatus(payload.progress || 12, payload.message || `Muxing ${format.label}... Please wait.`);
                    attachSocket(currentJobId);
                    pollJob(currentJobId, 0);
                } catch (error) {
                    debugLog('error', 'Format processing request failed.', error && error.message ? error.message : error);
                    statusText.textContent = error.message;
                } finally {
                    setLoading(false);
                }
            });

            formatsGrid.appendChild(button);
        });
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const sourceUrl = sourceInput.value.trim();
        if (!sourceUrl) {
            return;
        }

        if (isLikelyWatchPageUrl(sourceUrl)) {
            setStatus(
                0,
                'This server mode accepts direct media file links only (.mp4, .mp3, .webm). Watch-page URLs are not supported on shared hosting without external tools.'
            );
            formatsGrid.innerHTML = '';
            renderJobMeta({
                status: 'failed',
                message: 'Watch-page URL blocked before processing.',
                selected_format_label: 'None',
                result_url: null,
            });
            return;
        }

        setLoading(true);
        setStatus(12, 'Analyzing source and building format options...');
        formatsGrid.innerHTML = '';
        renderJobMeta({ status: 'analyzing' });
        disconnectSocket();

        try {
            const response = await fetch(config.restUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Yt2s-Nonce': config.nonce,
                },
                body: JSON.stringify({
                    source_url: sourceUrl,
                }),
            });

            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload.message || payload.detail || 'Source analysis failed.');
            }

            currentJobId = payload.job_id || '';
            currentFormats = Array.isArray(payload.formats) ? payload.formats : [];
            renderFormats(currentFormats, currentJobId, sourceUrl);
            renderJobMeta(payload);
            if (payload.message && payload.message.includes('Demo mode')) {
                setStatus(payload.progress || 20, payload.message + ' (Go to WordPress Settings > Yt2s Core to configure engine URL)');
            } else {
                setStatus(payload.progress || 20, payload.message || 'Source analyzed. Select a format to continue.');
            }
            attachSocket(currentJobId);
        } catch (error) {
            debugLog('error', 'Source analysis failed.', error && error.message ? error.message : error);
            statusText.textContent = error.message;
        } finally {
            setLoading(false);
        }
    });

    if (sourceInput) {
        sourceInput.focus();
    }
})();
