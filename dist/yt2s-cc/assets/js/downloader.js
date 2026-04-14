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
        jobMeta.innerHTML = `
            <p class="font-semibold text-white">Job details</p>
            <div class="mt-3 space-y-2 text-slate-300">
                <p><span class="text-slate-500">Job ID:</span> ${job.job_id || 'Pending'}</p>
                <p><span class="text-slate-500">Status:</span> ${job.status || 'idle'}</p>
                <p><span class="text-slate-500">Selected format:</span> ${job.selected_format_label || 'None'}</p>
            </div>
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

        if (!window.io || !config.socketUrl) {
            return;
        }

        currentSocket = window.io(config.socketUrl, {
            transports: ['websocket', 'polling'],
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

        currentSocket.on('connect_error', () => {
            statusText.textContent = 'Live progress is unavailable, falling back to polling.';
        });
    };

    const pollJob = async (jobId) => {
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
                return;
            }

            const payload = await response.json();
            setStatus(payload.progress || 0, payload.message || 'Processing...');
            renderJobMeta(payload);

            if (payload.status !== 'completed' && payload.status !== 'failed') {
                window.setTimeout(() => pollJob(jobId), 1400);
            }
        } catch (error) {
            window.setTimeout(() => pollJob(jobId), 1800);
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
                    pollJob(currentJobId);
                } catch (error) {
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
            setStatus(payload.progress || 20, payload.message || 'Source analyzed. Select a format to continue.');
            attachSocket(currentJobId);
        } catch (error) {
            statusText.textContent = error.message;
        } finally {
            setLoading(false);
        }
    });

    if (sourceInput) {
        sourceInput.focus();
    }
})();
