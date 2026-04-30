$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$stateFile = Join-Path (Join-Path $root '.local-dev') 'processes.json'

if (-not (Test-Path $stateFile)) {
    Write-Host "No tracked local services are running." -ForegroundColor Yellow
    exit 0
}

$processes = Get-Content -Path $stateFile -Raw | ConvertFrom-Json

foreach ($process in @($processes)) {
    if ($null -eq $process.pid) {
        continue
    }

    Stop-Process -Id $process.pid -Force -ErrorAction SilentlyContinue
}

Remove-Item -Path $stateFile -Force -ErrorAction SilentlyContinue

Write-Host "Local services stopped." -ForegroundColor Green
