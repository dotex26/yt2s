<?php
get_header();
?>
<main class="relative overflow-hidden px-4 py-10 sm:px-6 lg:px-8">
    <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_top,rgba(0,153,255,0.18),transparent_35%),radial-gradient(circle_at_bottom_right,rgba(255,122,89,0.12),transparent_28%)]"></div>
    <div class="relative mx-auto flex min-h-[calc(100vh-5rem)] max-w-6xl items-center justify-center">
        <section class="glass-panel w-full max-w-4xl rounded-[2rem] border border-white/10 p-6 shadow-[0_30px_100px_rgba(0,0,0,0.45)] sm:p-8 lg:p-10">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-xs font-semibold uppercase tracking-[0.35em] text-cyan-300/90">Yt2s.cc</p>
                <h1 class="mt-4 text-4xl font-semibold tracking-tight text-white sm:text-5xl">Studio-grade media packaging</h1>
                <p class="mt-4 text-base leading-7 text-slate-300">
                    Enter an authorized media URL, review the available formats, and track the processing job in real time.
                </p>
            </div>

            <div class="mt-10 grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
                <div class="space-y-5">
                    <form id="yt2s-form" class="space-y-4">
                        <label class="block text-sm font-medium text-slate-200" for="source-url">Source URL</label>
                        <div class="search-shell flex items-center gap-3 rounded-2xl border border-cyan-300/20 bg-slate-950/80 p-3 shadow-[0_0_0_1px_rgba(14,165,233,0.08),0_24px_80px_rgba(2,6,23,0.55)]">
                            <input id="source-url" name="source_url" type="url" required placeholder="https://media.example.com/asset.mp4" class="min-w-0 flex-1 border-0 bg-transparent px-2 py-3 text-base text-white outline-none placeholder:text-slate-500" />
                            <button type="submit" class="rounded-xl bg-cyan-400 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 focus:outline-none focus:ring-2 focus:ring-cyan-300">Analyze</button>
                        </div>
                    </form>

                    <div id="status-card" class="rounded-2xl border border-white/10 bg-slate-950/60 p-5">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-sm font-medium text-slate-300">Processing status</p>
                                <p id="status-text" class="mt-1 text-lg font-semibold text-white">Waiting for a source URL.</p>
                            </div>
                            <div class="text-right">
                                <p id="status-percent" class="text-3xl font-semibold text-cyan-300">0%</p>
                                <p class="text-xs uppercase tracking-[0.24em] text-slate-500">Progress</p>
                            </div>
                        </div>
                        <div class="mt-4 h-2 overflow-hidden rounded-full bg-white/5">
                            <div id="progress-bar" class="h-full w-0 rounded-full bg-gradient-to-r from-cyan-400 via-sky-500 to-orange-400 transition-all duration-300"></div>
                        </div>
                    </div>

                    <div id="skeleton" class="hidden space-y-4 rounded-2xl border border-white/10 bg-slate-950/55 p-5">
                        <div class="skeleton-line h-5 w-2/3 rounded-full"></div>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div class="skeleton-card h-24 rounded-2xl"></div>
                            <div class="skeleton-card h-24 rounded-2xl"></div>
                            <div class="skeleton-card h-24 rounded-2xl"></div>
                        </div>
                    </div>
                </div>

                <aside class="space-y-5">
                    <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-5">
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-white">Quality Grid</h2>
                            <span class="text-xs uppercase tracking-[0.28em] text-slate-500">MP4 / MP3</span>
                        </div>
                        <div id="formats-grid" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-1"></div>
                    </div>

                    <div id="job-meta" class="rounded-2xl border border-white/10 bg-slate-950/60 p-5 text-sm text-slate-300">
                        <p class="font-semibold text-white">Job details</p>
                        <p class="mt-2">Formats will appear here after the source is analyzed.</p>
                    </div>
                </aside>
            </div>
        </section>
    </div>
</main>
<?php
get_footer();
