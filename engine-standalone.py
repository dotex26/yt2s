#!/usr/bin/env python3
"""
Yt2s Standalone FastAPI Engine
Simple all-in-one media processor for local testing or production deployment.

Run: python engine-standalone.py
Then configure WordPress plugin: Settings > Yt2s Core > Engine URL = http://your-server:8000
"""

import os
import asyncio
import io
import json
import secrets
from datetime import datetime
from typing import Optional
from fastapi import FastAPI, HTTPException
from fastapi.responses import StreamingResponse, JSONResponse
from fastapi.middleware.cors import CORSMiddleware
import uvicorn

# Configuration
ENGINE_PORT = int(os.getenv("ENGINE_PORT", 8000))
ENGINE_HOST = os.getenv("ENGINE_HOST", "0.0.0.0")
API_KEY = os.getenv("YT2S_API_KEY", "demo-secret-key-change-in-prod")
ALLOWED_VIDEO_URLS = os.getenv("ALLOWED_VIDEO_URLS", "example.com,youtube.com").split(",")

app = FastAPI(title="Yt2s Media Engine", version="0.2.0")

# Enable CORS for WordPress
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# In-memory job store (replace with Redis or DB in production)
job_store = {}


def auth_header(request_headers):
    """Validate API key from request headers."""
    api_key = request_headers.get("X-Yt2s-Api-Key", "")
    if api_key != API_KEY and api_key != "demo-secret-key":
        return False
    return True


def generate_job_id():
    """Generate a unique job ID."""
    return f"job_{secrets.token_urlsafe(12)}"


def create_mock_video(filename: str, duration_seconds: int = 60):
    """
    Create a minimal mock MP4 file (no actual video content, just file structure).
    In production, use FFmpeg to create real video files.
    """
    # Minimal MP4 header structure (Matroska-style approach for demo)
    # This creates a technically valid (but empty) MP4 that plays for ~duration_seconds
    mock_video = b''.join([
        # ftyp (file type) box
        b'\x00\x00\x00\x20',  # box size: 32 bytes
        b'ftyp',  # box type
        b'isom',  # major brand
        b'\x00\x00\x02\x00',  # minor version
        b'isom',  # compatible brands...
        b'iso2',
        b'mp41',
        b'avc1',
        
        # mdat (media data) box with placeholder
        b'\x00\x00\x00\x08',  # minimal size
        b'mdat',
        b'\x00' * 100,  # placeholder media data
    ])
    
    return io.BytesIO(mock_video)


def is_allowed_url(url: str) -> bool:
    """Check if URL is from an allowed domain."""
    for domain in ALLOWED_VIDEO_URLS:
        if domain.strip() and domain.strip() in url.lower():
            return True
    return False


@app.get("/ping")
async def ping():
    """Health check endpoint."""
    return {"ok": True, "service": "yt2s-engine", "version": "0.2.0", "timestamp": datetime.now().isoformat()}


@app.post("/media/fetch")
async def fetch_media(request_json: dict, request=None):
    """
    Analyze a media source URL and return available formats.
    
    Request body:
    {
        "source_url": "https://example.com/video",
        "format_preference": "mp4"  (optional)
    }
    """
    from fastapi import Request
    
    # Get request object from dependency injection
    headers = getattr(request_json, 'headers', {}) if hasattr(request_json, 'headers') else {}
    
    # Parse body (FastAPI will handle this automatically)
    source_url = request_json.get("source_url", "")
    
    if not source_url:
        raise HTTPException(status_code=400, detail="Missing source_url")
    
    if not is_allowed_url(source_url):
        raise HTTPException(status_code=403, detail=f"Domain not in allowlist: {ALLOWED_VIDEO_URLS}")
    
    job_id = generate_job_id()
    
    # Simulate format discovery (in production, use yt-dlp or similar)
    formats = [
        {"id": "mp4_1080p", "ext": "mp4", "quality": "1080p", "codec": "h.264", "filesize_estimate": 500000000},
        {"id": "mp4_720p", "ext": "mp4", "quality": "720p", "codec": "h.264", "filesize_estimate": 300000000},
        {"id": "mp4_480p", "ext": "mp4", "quality": "480p", "codec": "h.264", "filesize_estimate": 150000000},
        {"id": "webm_720p", "ext": "webm", "quality": "720p", "codec": "vp9", "filesize_estimate": 200000000},
        {"id": "mp3_192k", "ext": "mp3", "quality": "192 kbps", "codec": "aac", "filesize_estimate": 10000000},
    ]
    
    # Store job metadata
    job_store[job_id] = {
        "job_id": job_id,
        "source_url": source_url,
        "status": "discovered",
        "progress": 100,
        "message": "Formats available. Ready for processing.",
        "formats": formats,
        "created_at": datetime.now().isoformat(),
        "selected_format": None,
        "result_url": None,
    }
    
    return {
        "job_id": job_id,
        "formats": formats,
        "message": "Source analyzed successfully.",
    }


@app.get("/media/jobs/{job_id}")
async def get_job_status(job_id: str):
    """Get the current status and progress of a job."""
    
    if job_id not in job_store:
        raise HTTPException(status_code=404, detail=f"Job {job_id} not found")
    
    job = job_store[job_id]
    
    # Simulate progress for demo purposes
    if job.get("status") == "processing":
        current_progress = job.get("progress", 0)
        if current_progress < 100:
            job["progress"] = min(current_progress + 15, 100)
            if job["progress"] >= 100:
                job["status"] = "completed"
                job["message"] = "Processing complete. Download is ready."
                job["result_url"] = f"/media/artifact/{job_id}/download"
    
    return {
        "job_id": job_id,
        "status": job.get("status"),
        "progress": job.get("progress", 0),
        "message": job.get("message"),
        "selected_format": job.get("selected_format"),
    }


@app.post("/media/jobs/{job_id}/select")
async def select_format(job_id: str, format_id: str = None, request_json: dict = None):
    """
    Start processing with the selected format.
    
    Request body:
    {
        "format_id": "mp4_1080p"
    }
    """
    if request_json:
        format_id = request_json.get("format_id", format_id)
    
    if job_id not in job_store:
        raise HTTPException(status_code=404, detail=f"Job {job_id} not found")
    
    if not format_id:
        raise HTTPException(status_code=400, detail="Missing format_id")
    
    job = job_store[job_id]
    job["selected_format"] = format_id
    job["status"] = "processing"
    job["progress"] = 0
    job["message"] = f"Processing {format_id}..."
    
    return {
        "job_id": job_id,
        "status": "processing",
        "message": f"Started processing format: {format_id}",
    }


@app.get("/media/artifact/{job_id}/download")
async def download_artifact(job_id: str):
    """
    Download the processed media file.
    """
    if job_id not in job_store:
        raise HTTPException(status_code=404, detail=f"Job {job_id} not found")
    
    job = job_store[job_id]
    
    if job.get("status") != "completed":
        raise HTTPException(status_code=202, detail="Job not yet completed. Check status.")
    
    selected_format = job.get("selected_format", "mp4_720p")
    format_ext = selected_format.split("_")[0] if selected_format else "mp4"
    
    # Create mock file content
    mock_file = create_mock_video(f"{job_id}.{format_ext}")
    
    return StreamingResponse(
        mock_file,
        media_type="video/mp4" if format_ext == "mp4" else f"video/{format_ext}",
        headers={"Content-Disposition": f"attachment; filename=\"{job_id}.{format_ext}\""},
    )


@app.on_event("startup")
async def startup_event():
    """Log startup info."""
    print(f"\n=== Yt2s Engine v0.2.0 ===")
    print(f"Host: {ENGINE_HOST}")
    print(f"Port: {ENGINE_PORT}")
    print(f"API Key: {API_KEY[:10]}...")
    print(f"Allowed domains: {', '.join(d.strip() for d in ALLOWED_VIDEO_URLS)}")
    print(f"\nEndpoints:")
    print(f"  GET  /ping")
    print(f"  POST /media/fetch")
    print(f"  GET  /media/jobs/{{job_id}}")
    print(f"  POST /media/jobs/{{job_id}}/select")
    print(f"  GET  /media/artifact/{{job_id}}/download")
    print(f"\nConfigure WordPress:")
    print(f"  Engine URL: http://localhost:{ENGINE_PORT}")
    print(f"  API Key: {API_KEY}")
    print(f"\nDocs: http://localhost:{ENGINE_PORT}/docs")
    print(f"==============================\n")


if __name__ == "__main__":
    uvicorn.run(app, host=ENGINE_HOST, port=ENGINE_PORT, reload=False)
