# Deploying Stickman Animation Studio

## Option A — Docker (recommended for a VPS)

```dockerfile
# Dockerfile
FROM node:20-bookworm-slim

# FFmpeg only if you skip the npm-provided static build
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY stickman-studio/package*.json ./
RUN npm ci --omit=dev
COPY stickman-studio/ ./

ENV NODE_ENV=production PORT=4173
EXPOSE 4173
VOLUME ["/app/data"]
CMD ["node", "server/index.js"]
```

```bash
docker build -t stickman-studio .
docker run -d --name stickman -p 4173:4173 -v stickman-data:/app/data stickman-studio
```

`/app/data` holds `projects/` (JSON), `exports/` (MP4) and `media/` (narration) —
mount it so work survives container restarts.

## Option B — plain VPS (Ubuntu/Debian)

```bash
sudo apt update && sudo apt install -y nodejs npm ffmpeg
cd /opt && git clone <your-repo> && cd stickman-studio
npm install --omit=dev
npm run setup-ffmpeg      # optional, uses the bundled static build
sudo tee /etc/systemd/system/stickman.service >/dev/null <<'UNIT'
[Unit]
Description=Stickman Animation Studio
After=network.target
[Service]
WorkingDirectory=/opt/stickman-studio
ExecStart=/usr/bin/node server/index.js
Environment=PORT=4173
Restart=always
[Install]
WantedBy=multi-user.target
UNIT
sudo systemctl enable --now stickman
```

## Option C — PaaS (Fly.io / Railway / Render)

* Build: `npm install`; Start: `node server/index.js`; Port: `4173`.
* Add a persistent volume at `data/` (exports would otherwise vanish on redeploy).
* Give it at least 1 GB RAM and 2 vCPU: 1080p30 runs at about 2.5–5× real time.

## Reverse proxy (HTTPS + uploads)

```nginx
server {
  listen 443 ssl http2;
  server_name studio.example.com;
  client_max_body_size 64m;          # narration uploads
  location / {
    proxy_pass http://127.0.0.1:4173;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_read_timeout 900s;         # long renders / downloads
  }
}
```

HTTPS matters: without it, `getUserMedia`/file pickers and some Chrome audio
behaviours degrade, and you cannot install the PWA properly.

## Choosing the FFmpeg source

| Order | Source | When it is used |
|---|---|---|
| 1 | `FFMPEG_PATH=/usr/bin/ffmpeg` | you want a specific build |
| 2 | `ffmpeg` on `PATH` | apt/docker installed one |
| 3 | `node_modules/@ffmpeg-installer/ffmpeg` | default after `npm install` |
| 4 | download into `vendor/` or `~/.cache/stickman-studio/` | air-gapped from 1–3, needs registry access once |

`GET /api/health` reports which one is in use and whether export is available.

## Capacity & behaviour notes

* Frame rendering costs ~55 ms at 1920×1080 in this container (≈ 1.4 s of film per
  second of CPU on one core); encoding is the bottleneck at `veryfast`.
* One render job per process. `/api/health` exposes `activeJobs`; for multiple users
  add a queue (BullMQ / a simple mutex) in front of `POST /api/render`.
* Exports are plain files in `data/exports`; add a cron `find … -mtime +14 -delete`
  to keep the disk tidy.
* Bandwidth: a 15 s 1080p film is ≈ 5 MB (CRF 18). Ten exports ≈ 50 MB.

## Cost

Nothing in this project requires a paid account or an API key. The only ongoing cost
is the host you run it on (a €4–5 VPS is enough for personal use) and optional
narration recording, which you can do with the phone's own recorder.
