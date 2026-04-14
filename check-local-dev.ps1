$ErrorActionPreference = 'Stop'

Set-Location $PSScriptRoot

Write-Host 'Checking local development prerequisites...'

$docker = Get-Command docker -ErrorAction SilentlyContinue
if ($null -eq $docker) {
    Write-Host 'Docker: not found'
    Write-Host 'Install Docker Desktop for Windows, then rerun start-dev.ps1.'
} else {
    Write-Host "Docker: found at $($docker.Source)"
}

$python = Get-Command python -ErrorAction SilentlyContinue
if ($null -eq $python) {
    Write-Host 'Python: not found'
} else {
    Write-Host "Python: found at $($python.Source)"
}

$php = Get-Command php -ErrorAction SilentlyContinue
if ($null -eq $php) {
    Write-Host 'PHP: not found'
} else {
    Write-Host "PHP: found at $($php.Source)"
}

if (Test-Path '.env') {
    Write-Host '.env: present'
} else {
    Write-Host '.env: missing (copy from .env.example if using Docker)'
}

if (Test-Path 'engine\.env') {
    Write-Host 'engine\.env: present'
} else {
    Write-Host 'engine\.env: missing (copy from engine\.env.example if using Docker)'
}
