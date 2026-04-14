$ErrorActionPreference = 'Stop'

Set-Location $PSScriptRoot

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker is not installed or not available on PATH.'
}

& docker compose down

if ($LASTEXITCODE -ne 0) {
    throw 'Docker Compose failed to stop the stack.'
}

Write-Host 'Stack stopped.'
