# Yt2s Scaffold

This workspace contains a WordPress front-end scaffold and a FastAPI media engine scaffold.

The implementation is intentionally source-restricted: it is designed for user-owned or licensed media sources only. The WordPress plugin proxies requests to the Python engine, and the custom page template renders the downloader UI.

## Local Development

The easiest way to run everything together on a local machine is Docker Compose.

1. Copy `.env.example` to `.env` at the repository root.
2. Copy `engine/.env.example` to `engine/.env` if you want a separate engine environment file, or rely on the Compose variables.
3. Start the stack with `./start-dev.ps1` on Windows or `docker compose up --build -d`.
4. Open WordPress at `http://localhost:8080` and complete the initial setup.
5. Activate the `Yt2s Core` plugin and assign the `Downloader` page template to a page.
6. Use the default shared secret from the `.env` file, or change it in both places before testing.

The Windows starter waits until WordPress responds before it prints the success message, so you do not have to guess when the site is ready.

Services exposed by the stack:

- WordPress: `http://localhost:8080`
- FastAPI engine: `http://localhost:8000`
- MariaDB: internal only

If `http://localhost:8080/` shows `ERR_CONNECTION_REFUSED`, check these first:

1. Docker Desktop is running.
2. `docker compose ps` shows the `wordpress` service as `Up`.
3. `docker compose logs wordpress` does not show a database connection failure.
4. Port `8080` is not already in use by another process.
5. Re-run `./start-dev.ps1` after fixing the issue.

## No Docker Available

If Docker is not installed on your Windows machine, use a local WordPress stack such as LocalWP or XAMPP instead:

1. Install LocalWP or XAMPP.
2. Create a local WordPress site.
3. Copy `wp-content/plugins/yt2s-core/` into the site's plugin directory.
4. Copy `wp-content/themes/yt2s/` into the site's theme directory.
5. Run the Python engine separately with `python -m uvicorn app.main:application --host 0.0.0.0 --port 8000` from the `engine/` folder.
6. Set the plugin's Engine URL and Socket URL to `http://127.0.0.1:8000`.
7. Set the Shared Secret to match `YT2S_API_KEY` in the engine environment.

This path avoids Docker entirely, but you still need a local web server, PHP, and a database from the WordPress stack tool you choose.

## Layout

- `wp-content/plugins/yt2s-core/` - WordPress plugin that forwards requests to the engine.
- `wp-content/themes/yt2s/` - WordPress theme with the downloader page template and UI assets.
- `engine/` - FastAPI application with job state, format discovery, and Socket.IO progress updates.

## Next steps

1. Configure the plugin settings in WordPress with the engine URL and shared secret.
2. Run the FastAPI engine from the `engine/` directory.
3. Assign the `page-downloader.php` template to a WordPress page.
4. Replace the simulated source adapter with your authorized source integrations.
5. Replace the simulated processing step with real authorized-source adapters.
