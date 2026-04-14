from __future__ import annotations

import os

import socketio
from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from .routes.media import router as media_router
from .services.progress import JobStore


def _split_origins(raw_value: str) -> list[str] | str:
    if raw_value.strip() == '*':
        return '*'

    origins = [item.strip() for item in raw_value.split(',') if item.strip()]
    return origins or ['*']


api = FastAPI(title='Yt2s Engine', version='0.1.0')

allowed_origins = _split_origins(os.getenv('YT2S_CORS_ORIGINS', '*'))
api.add_middleware(
    CORSMiddleware,
    allow_origins=['*'] if allowed_origins == '*' else allowed_origins,
    allow_credentials=True,
    allow_methods=['*'],
    allow_headers=['*'],
)

api.state.store = JobStore()
api.state.api_key = os.getenv('YT2S_API_KEY', '')
api.state.sio = socketio.AsyncServer(async_mode='asgi', cors_allowed_origins=allowed_origins)
api.include_router(media_router, prefix='/media')

sio = api.state.sio


@sio.event
async def connect(sid, environ, auth):
    return True


@sio.event
async def subscribe(sid, data):
    job_id = (data or {}).get('job_id')
    if not job_id:
        return

    await sio.enter_room(sid, job_id)
    job = await api.state.store.get_job(job_id)
    if job is not None:
        await sio.emit('progress', job.snapshot().model_dump(), room=job_id)


@sio.event
async def unsubscribe(sid, data):
    job_id = (data or {}).get('job_id')
    if not job_id:
        return

    await sio.leave_room(sid, job_id)


application = socketio.ASGIApp(sio, other_asgi_app=api)


if __name__ == '__main__':
    import uvicorn

    uvicorn.run('app.main:application', host='0.0.0.0', port=8000, reload=True)
