# Render Deployment - Complete Setup

## Your Generated API Key (SAVE THIS!)

```
API Key: L3I2nKJeknhV9B8EgI7bVE_DiGthyd_gnNwiTip10QY
```

**⚠️ KEEP THIS SECRET** — Don't share it publicly. Use this in WordPress settings later.

---

## Step 1: Deploy to Render

1. Go to [render.com](https://render.com)
2. Click **Dashboard** → **New** → **Web Service**
3. Select repository: `dotex26/yt2s`
4. Fill in:
   - **Name:** `yt2s-engine`
   - **Environment:** `Python 3`
   - **Build Command:** `pip install -r requirements.txt`
   - **Start Command:** `python engine-standalone.py`
   - **Instance Type:** `Free`

5. Click **Advanced** → **Add Environment Variable** for each:

| Key | Value |
|-----|-------|
| `ENGINE_HOST` | `0.0.0.0` |
| `ENGINE_PORT` | `8000` |
| `YT2S_API_KEY` | `L3I2nKJeknhV9B8EgI7bVE_DiGthyd_gnNwiTip10QY` |
| `ALLOWED_VIDEO_URLS` | `youtube.com,vimeo.com,dailymotion.com,example.com` |
| `PYTHONUNBUFFERED` | `1` |

6. Click **Create Web Service** → Wait 2-3 minutes for deployment

---

## Step 2: Get Your Engine URL

Once deployed, Render shows your URL. Example:
```
https://yt2s-engine-a1b2c3d4.onrender.com
```

Copy this URL. You'll need it for WordPress.

---

## Step 3: Configure WordPress on GoViralHost

SSH or File Manager → Update plugin settings:

1. Login to WordPress admin on your GoViralHost domain
2. Go **Settings** → **Yt2s Core**
3. Enter:
   - **Engine URL:** `https://yt2s-engine-a1b2c3d4.onrender.com` (your actual Render URL)
   - **Shared API Key:** `L3I2nKJeknhV9B8EgI7bVE_DiGthyd_gnNwiTip10QY`
4. Click **Save Changes**

---

## Step 4: Test

1. Refresh your downloader page
2. Analyze a video → Select format → Download
3. Should now download real files (mock MP4s for demo)

---

## Important Notes

- **Free Tier:** Server sleeps after 15 min of inactivity. First request takes 30 sec to wake up.
- **Custom Domain:** If you want `engine.yourdomain.com`, add custom domain in Render settings.
- **Upgrade Later:** If you need production-grade, upgrade to paid plan (Render has $7/month paid tiers).
- **API Key Security:** This key is now only stored in Render environment and WordPress settings. Don't commit it to Git.

---

## Test Render Directly

Before configuring WordPress, test your Render URL:

```bash
curl "https://yt2s-engine-a1b2c3d4.onrender.com/ping" \
  -H "X-Yt2s-Api-Key: L3I2nKJeknhV9B8EgI7bVE_DiGthyd_gnNwiTip10QY"
```

Should return:
```json
{
  "ok": true,
  "service": "yt2s-engine",
  "version": "0.2.0",
  "timestamp": "2026-04-14T..."
}
```

---

## Environment Variables Explained

| Variable | Purpose | Example |
|----------|---------|---------|
| `ENGINE_HOST` | Server bind address | `0.0.0.0` (listen on all IPs) |
| `ENGINE_PORT` | Port FastAPI listens on | `8000` |
| `YT2S_API_KEY` | Secret for authentication | Your generated key |
| `ALLOWED_VIDEO_URLS` | Domains allowed to process | Comma-separated: `youtube.com,example.com` |
| `PYTHONUNBUFFERED` | Real-time logs in Render console | `1` |
