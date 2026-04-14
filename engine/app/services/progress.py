from __future__ import annotations

import asyncio
from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Any
from uuid import uuid4

from ..schemas import FormatOption, JobSnapshot


@dataclass
class JobRecord:
    job_id: str
    source_url: str
    status: str = 'ready'
    progress: int = 0
    message: str = 'Source analyzed.'
    selected_format_id: str | None = None
    selected_format_label: str | None = None
    formats: list[FormatOption] = field(default_factory=list)
    result_url: str | None = None
    mux_command: str | None = None
    created_at: str = field(default_factory=lambda: datetime.now(timezone.utc).isoformat())
    updated_at: str = field(default_factory=lambda: datetime.now(timezone.utc).isoformat())

    def snapshot(self) -> JobSnapshot:
        return JobSnapshot(
            job_id=self.job_id,
            source_url=self.source_url,
            status=self.status,
            progress=self.progress,
            message=self.message,
            selected_format_id=self.selected_format_id,
            selected_format_label=self.selected_format_label,
            formats=self.formats,
            result_url=self.result_url,
            mux_command=self.mux_command,
        )


class JobStore:
    def __init__(self) -> None:
        self._jobs: dict[str, JobRecord] = {}
        self._lock = asyncio.Lock()

    async def create_job(self, source_url: str, formats: list[FormatOption]) -> JobRecord:
        async with self._lock:
            job_id = uuid4().hex[:12]
            job = JobRecord(job_id=job_id, source_url=source_url, formats=formats)
            self._jobs[job_id] = job
            return job

    async def get_job(self, job_id: str) -> JobRecord | None:
        async with self._lock:
            return self._jobs.get(job_id)

    async def update_job(self, job_id: str, **changes: Any) -> JobRecord | None:
        async with self._lock:
            job = self._jobs.get(job_id)
            if job is None:
                return None

            for key, value in changes.items():
                setattr(job, key, value)

            job.updated_at = datetime.now(timezone.utc).isoformat()
            return job

    async def ensure_job(self, source_url: str, formats: list[FormatOption], job_id: str | None) -> JobRecord:
        if job_id:
            existing = await self.get_job(job_id)
            if existing is not None:
                return existing

        return await self.create_job(source_url, formats)

    @staticmethod
    def default_formats() -> list[FormatOption]:
        return [
            FormatOption(id='mp4-4k', label='MP4 4K', kind='video', container='mp4', note='High-resolution video package.'),
            FormatOption(id='mp4-1080', label='MP4 1080p', kind='video', container='mp4', note='Balanced quality and size.'),
            FormatOption(id='mp4-720', label='MP4 720p', kind='video', container='mp4', note='Fastest video export.'),
            FormatOption(id='mp3-320', label='MP3 320kbps', kind='audio', container='mp3', bitrate='320kbps', note='High-bitrate audio package.'),
            FormatOption(id='mp3-128', label='MP3 128kbps', kind='audio', container='mp3', bitrate='128kbps', note='Compact audio package.'),
        ]
