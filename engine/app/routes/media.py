from __future__ import annotations

from fastapi import APIRouter, BackgroundTasks, HTTPException, Request
from fastapi.responses import JSONResponse

from ..schemas import FetchRequest, FormatOption
from ..services.processor import process_job
from ..services.progress import JobStore

router = APIRouter()


def _get_store(request: Request) -> JobStore:
    return request.app.state.store


def _require_api_key(request: Request) -> None:
    expected_key = request.app.state.api_key
    if expected_key:
        supplied_key = request.headers.get('x-yt2s-api-key', '')
        if supplied_key != expected_key:
            raise HTTPException(status_code=403, detail='Invalid shared secret.')


@router.post('/fetch')
async def fetch_media(request: Request, payload: FetchRequest, background_tasks: BackgroundTasks):
    _require_api_key(request)
    store = _get_store(request)

    default_formats = store.default_formats()
    job = await store.ensure_job(payload.source_url, default_formats, payload.job_id)

    if payload.format_id:
        selected_format = next((item for item in job.formats if item.id == payload.format_id), None)
        if selected_format is None:
            raise HTTPException(status_code=404, detail='Requested format is unavailable.')

        if job.status not in {'processing', 'completed'}:
            await store.update_job(
                job.job_id,
                status='processing',
                progress=5,
                message=f'Muxing {selected_format.label}... Please wait.',
                selected_format_id=selected_format.id,
                selected_format_label=selected_format.label,
            )

            async def _publish(job_id: str, percent: int, message: str) -> None:
                updated_job = await store.update_job(job_id, progress=percent, message=message)
                if updated_job is None:
                    return

                snapshot = updated_job.snapshot().model_dump()
                await request.app.state.sio.emit('progress', snapshot, room=job_id)

            async def _run_job() -> None:
                latest_job = await store.get_job(job.job_id)
                if latest_job is None:
                    return

                await process_job(latest_job, selected_format, _publish)

                completed_job = await store.update_job(
                    job.job_id,
                    status='completed',
                    progress=100,
                    message='Processing complete.',
                )

                if completed_job is None:
                    return

                snapshot = completed_job.snapshot().model_dump()
                await request.app.state.sio.emit('progress', snapshot, room=job.job_id)
                await request.app.state.sio.emit('job_complete', snapshot, room=job.job_id)

            background_tasks.add_task(_run_job)

        current_job = await store.get_job(job.job_id)
        if current_job is None:
            raise HTTPException(status_code=404, detail='Job no longer exists.')

        snapshot = current_job.snapshot().model_dump()
        await request.app.state.sio.emit('progress', snapshot, room=job.job_id)
        return JSONResponse(snapshot)

    snapshot = job.snapshot().model_dump()
    return JSONResponse(snapshot)


@router.get('/jobs/{job_id}')
async def get_job(request: Request, job_id: str):
    _require_api_key(request)
    job = await _get_store(request).get_job(job_id)

    if job is None:
        raise HTTPException(status_code=404, detail='Job not found.')

    return JSONResponse(job.snapshot().model_dump())


@router.get('/jobs/{job_id}/artifact')
async def get_artifact(request: Request, job_id: str):
    _require_api_key(request)
    job = await _get_store(request).get_job(job_id)

    if job is None:
        raise HTTPException(status_code=404, detail='Job not found.')

    if job.status != 'completed':
        raise HTTPException(status_code=409, detail='Artifact is not ready yet.')

    return JSONResponse(
        {
            'job_id': job.job_id,
            'download_ready': True,
            'result_url': job.result_url,
            'mux_command': job.mux_command,
        }
    )
