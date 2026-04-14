# Yt2s Engine Setup Guide

## Current Status

You're running in **DEMO MODE**. The WordPress plugin is falling back to a built-in demo processor because no real FastAPI engine is configured. Your downloads are working, but only returning demo text files.

### What's Downloaded Now (Demo Mode)
```
Yt2s Demo Artifact
Job ID: 3YvLhIYWi8kV
Selected Format: MP4 1080p
Source URL: https://www.youtube.com/...
This is a demo output. Configure a live engine endpoint for real files.
```

---

## Option 1: Run Engine Locally (for development/testing)

### Prerequisites
- Python 3.9+
- FastAPI (`pip install fastapi uvicorn`)

### Steps

1. **Copy engine file:**
   ```bash
   cp e:\yt2s\engine-standalone.py C:\your\project\folder\
   ```

2. **Install dependencies:**
   ```bash
   pip install fastapi uvicorn python-socketio
   ```

3. **Run the engine:**
   ```bash
   python engine-standalone.py
   ```
   
   Output:
   ```
   === Yt2s Engine v0.2.0 ===
   Host: 0.0.0.0
   Port: 8000
   API Key: demo-secret-key-change-in-prod
   Allowed domains: example.com,youtube.com
   
   Endpoints:
     GET  /ping
     POST /media/fetch
     GET  /media/jobs/{job_id}
     POST /media/jobs/{job_id}/select
     GET  /media/artifact/{job_id}/download
   
   Configure WordPress:
     Engine URL: http://localhost:8000
     API Key: demo-secret-key-change-in-prod
   ```

4. **Configure WordPress plugin:**
   - Go to `Settings` → `Yt2s Core`
   - Set **Engine URL** to `http://localhost:8000` (or your remote IP)
   - Set **API Key** to `demo-secret-key-change-in-prod` (or your custom key)
   - Save

5. **Test:**
   - Refresh your downloader page
   - Upload should now use the real engine
   - Downloads will be mock video files (not real video content, but proper file structure)

---

## Option 2: Deploy Engine to a Public Server

### Via Render (Free Tier)

1. **Create account at [render.com](https://render.com)**

2. **Deploy:**
   - New Service → Web Service
   - Connect your GitHub repo (or upload `engine-standalone.py`)
   - Runtime: Python 3.12
   - Build command: `pip install -r requirements.txt`
   - Start command: `python engine-standalone.py`
   - Instance Type: Free (0.5 GB RAM, shared CPU)
   - Set environment variables:
     ```
     ENGINE_HOST=0.0.0.0
     ENGINE_PORT=8000
     YT2S_API_KEY=your-secret-key-here
     ALLOWED_VIDEO_URLS=example.com,youtube.com
     ```

3. **Configure WordPress:**
   - Engine URL: `https://your-app.onrender.com`
   - API Key: `your-secret-key-here`

### Via Railway / Heroku / AWS / DigitalOcean

Similar process: Push the `engine-standalone.py` file, set environment variables, expose port 8000.

---

## Option 3: Deploy on Your Own VPS

1. **SSH into your VPS:**
   ```bash
   ssh user@123.45.67.89
   ```

2. **Install Python & dependencies:**
   ```bash
   sudo apt update
   sudo apt install python3-pip
   pip3 install fastapi uvicorn python-socketio
   ```

3. **Upload `engine-standalone.py`:**
   ```bash
   scp engine-standalone.py user@123.45.67.89:/home/user/
   ```

4. **Run with systemd (persistent):**
   ```bash
   sudo nano /etc/systemd/system/yt2s-engine.service
   ```
   
   Content:
   ```
   [Unit]
   Description=Yt2s Media Engine
   After=network.target
   
   [Service]
   Type=simple
   User=www-data
   WorkingDirectory=/home/user
   ExecStart=/usr/bin/python3 /home/user/engine-standalone.py
   Restart=always
   Environment="ENGINE_PORT=8000"
   Environment="YT2S_API_KEY=your-secret-key"
   Environment="ALLOWED_VIDEO_URLS=example.com,youtube.com"
   
   [Install]
   WantedBy=multi-user.target
   ```
   
   Start:
   ```bash
   sudo systemctl enable yt2s-engine
   sudo systemctl start yt2s-engine
   sudo systemctl status yt2s-engine
   ```

5. **Configure reverse proxy (Nginx):**
   ```nginx
   server {
       listen 443 ssl;
       server_name engine.yourdomain.com;
       
       location / {
           proxy_pass http://localhost:8000;
           proxy_set_header X-Real-IP $remote_addr;
       }
   }
   ```

6. **Configure WordPress:**
   - Engine URL: `https://engine.yourdomain.com`
   - API Key: `your-secret-key`

---

## What's Different: Real Engine vs. Demo Mode

| Feature | Demo Mode | Real Engine |
|---------|-----------|-----------|
| **Download Format** | Text file with metadata | Actual video/audio file |
| **Processing** | Instant | Simulated async (currently mock) |
| **Artifact Size** | < 1 KB | 10+ MB (depends on format) |
| **Real Downloading** | ❌ No | ⚠️ Not yet (stub endpoints) |
| **Socket.IO** | Disabled by default | Enabled if configured |

---

## Next Steps After Engine Deployment

1. **Implement Real Media Processing**
   - Replace demo format list with actual format detection (via `yt-dlp` or source API)
   - Implement `process_job()` to actually download/transcode media
   - Generate real artifacts and store in cloud storage (S3, Google Drive, etc.)

2. **Add Authentication & Rate Limiting**
   - Implement per-user rate limits
   - Add database for job history
   - Log all processing events for auditing

3. **Production Hardening**
   - Add job cleanup (remove old artifacts after 24h)
   - Add error recovery (retry failed jobs)
   - Add monitoring & alerting (alert on 500 errors, slow responses)

---

## Troubleshooting

### "Connection refused" or "No route to host"
- Check WordPress settings: Is Engine URL correct?
- Check firewall: Is port 8000 open?
- Check engine status: Is it still running?

### "Job returns DEMO MODE status"
- Engine is not reachable; plugin reverted to demo fallback
- Check console: Is `[yt2s] Demo mode active...` message visible?

### "Download file is still text, not video"
- You're still in demo mode
- Restart WordPress plugin (deactivate/reactivate)
- Clear browser cache and WordPress transient cache

### "API Key rejected"
- Check WordPress settings: Is API Key correct?
- Check engine environment variable: Is `YT2S_API_KEY` set correctly?

---

## Security Notes

- **Never** use `demo-secret-key-change-in-prod` in production
- Always change `YT2S_API_KEY` to a strong random value
- Use HTTPS for all engine URLs
- Restrict `ALLOWED_VIDEO_URLS` to your actual authorized domains (YouTube, etc.)
- Run engine behind a firewall/proxy; don't expose port 8000 directly
