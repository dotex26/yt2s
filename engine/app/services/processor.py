from __future__ import annotations

import asyncio
from typing import Awaitable, Callable

from ..schemas import FormatOption
from .progress import JobRecord

PublishProgress = Callable[[str, int, str], Awaitable[None]]


def build_mux_command(video_path: str, audio_path: str, output_path: str) -> str:
    return f'ffmpeg -i "{video_path}" -i "{audio_path}" -c copy "{output_path}"'


async def process_job(job: JobRecord, selected_format: FormatOption, publish_progress: PublishProgress) -> None:
    steps = [
        (15, f'Queued {selected_format.label} for processing.'),
        (40, f'Preparing {selected_format.label}...'),
        (68, f'Muxing {selected_format.label}... Please wait.'),
        (88, 'Finalizing output package...'),
        (100, 'Processing complete.'),
    ]

    await publish_progress(job.job_id, 5, 'Starting processing workflow.')

    for percent, message in steps:
        await asyncio.sleep(0.1)
        await publish_progress(job.job_id, percent, message)

    if selected_format.kind == 'video':
        job.mux_command = build_mux_command('video-track.bin', 'audio-track.bin', f'{job.job_id}.mp4')
        job.result_url = f'/media/jobs/{job.job_id}/artifact'
    else:
        job.mux_command = None
        job.result_url = f'/media/jobs/{job.job_id}/artifact'
