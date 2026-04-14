param(
    [switch]$Detach = $true
)

$ErrorActionPreference = 'Stop'

Set-Location $PSScriptRoot

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker is not installed or not available on PATH. Install Docker Desktop and try again.'
}

if (-not (Test-Path '.env')) {
    Copy-Item '.env.example' '.env'
    Write-Host 'Created .env from .env.example'
}

if (-not (Test-Path 'engine\.env')) {
    Copy-Item 'engine\.env.example' 'engine\.env'
    Write-Host 'Created engine\.env from engine\.env.example'
}

$composeArgs = @('compose', 'up', '--build')
if ($Detach) {
    $composeArgs += '-d'
}

& docker @composeArgs

if ($LASTEXITCODE -ne 0) {
    throw 'Docker Compose failed to start the stack.'
}

function Test-HttpReady {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Uri
    )

    try {
        $response = Invoke-WebRequest -Uri $Uri -UseBasicParsing -TimeoutSec 5
        return $response.StatusCode -ge 200 -and $response.StatusCode -lt 500
    }
    catch {
        return $false
    }
}

Write-Host 'Waiting for WordPress to become reachable at http://localhost:8080 ...'

$deadline = (Get-Date).AddMinutes(5)
while ((Get-Date) -lt $deadline) {
    if (Test-HttpReady -Uri 'http://localhost:8080') {
        Write-Host ''
        Write-Host 'Stack is ready.'
        Write-Host 'WordPress: http://localhost:8080'
        Write-Host 'Engine:    http://localhost:8000'
        return
    }

    Start-Sleep -Seconds 3
}

Write-Warning 'WordPress did not become reachable within 5 minutes.'
Write-Host ''
Write-Host 'Try these checks:'
Write-Host '1. docker compose ps'
Write-Host '2. docker compose logs wordpress'
Write-Host '3. docker compose logs db'
Write-Host '4. docker compose logs engine'
throw 'Local stack did not become ready.'
