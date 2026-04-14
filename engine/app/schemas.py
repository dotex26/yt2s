from __future__ import annotations

from pydantic import BaseModel, Field


class FetchRequest(BaseModel):
    source_url: str = Field(min_length=1)
    job_id: str | None = None
    format_id: str | None = None


class FormatOption(BaseModel):
    id: str
    label: str
    kind: str
    container: str
    bitrate: str | None = None
    note: str | None = None


class JobSnapshot(BaseModel):
    job_id: str
    source_url: str
    status: str
    progress: int
    message: str
    selected_format_id: str | None = None
    selected_format_label: str | None = None
    formats: list[FormatOption] = Field(default_factory=list)
    result_url: str | None = None
    mux_command: str | None = None
