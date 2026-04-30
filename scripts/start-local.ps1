$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$stateDir = Join-Path $root '.local-dev'
$stateFile = Join-Path $stateDir 'processes.json'

if (-not (Test-Path $stateDir)) {
    New-Item -ItemType Directory -Path $stateDir | Out-Null
}

if (Test-Path $stateFile) {
    Write-Host "Local services appear to be running already. Stop them first with .\stop-local.cmd" -ForegroundColor Yellow
    exit 1
}

$services = @(
    @{
        Name = 'Laravel API'
        Workdir = Join-Path $root 'backend'
        Command = 'php artisan serve'
    },
    @{
        Name = 'Laravel Reverb'
        Workdir = Join-Path $root 'backend'
        Command = 'php artisan reverb:start'
    },
    @{
        Name = 'Laravel Queue'
        Workdir = Join-Path $root 'backend'
        Command = 'php artisan queue:work'
    },
    @{
        Name = 'Angular Frontend'
        Workdir = Join-Path $root 'front'
        Command = 'npm.cmd run start'
    },
    @{
        Name = 'Stripe Webhook'
        Workdir = $root
        Command = '.\stripe.exe listen --forward-to http://localhost:8000/api/stripe/webhook'
    }
)

$started = @()

try {
    foreach ($service in $services) {
        $escapedWorkdir = $service.Workdir.Replace("'", "''")
        $commandText = @"
`$Host.UI.RawUI.WindowTitle = '$($service.Name)';
Set-Location '$escapedWorkdir';
$($service.Command)
"@

        $process = Start-Process `
            -FilePath 'powershell.exe' `
            -ArgumentList @('-NoExit', '-ExecutionPolicy', 'Bypass', '-Command', $commandText) `
            -WorkingDirectory $service.Workdir `
            -PassThru

        $started += [pscustomobject]@{
            name = $service.Name
            pid = $process.Id
            workdir = $service.Workdir
            command = $service.Command
        }

        Start-Sleep -Milliseconds 300
    }

    $started | ConvertTo-Json | Set-Content -Path $stateFile -Encoding UTF8

    Write-Host "Local services started." -ForegroundColor Green
    Write-Host "Use .\stop-local.cmd to stop all started windows." -ForegroundColor Green
} catch {
    if ($started.Count -gt 0) {
        foreach ($item in $started) {
            Stop-Process -Id $item.pid -Force -ErrorAction SilentlyContinue
        }
    }

    if (Test-Path $stateFile) {
        Remove-Item -Path $stateFile -Force -ErrorAction SilentlyContinue
    }

    throw
}
