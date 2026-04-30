$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$stateDir = Join-Path $root '.local-dev'
$stateFile = Join-Path $stateDir 'inline-processes.json'
$logDir = Join-Path $stateDir 'inline-logs'

if (-not (Test-Path $stateDir)) {
    New-Item -ItemType Directory -Path $stateDir | Out-Null
}

if (Test-Path $stateFile) {
    Write-Host "Inline local services appear to be running already. Stop them with Ctrl+C or remove $stateFile if they crashed." -ForegroundColor Yellow
    exit 1
}

if (Test-Path $logDir) {
    Remove-Item -Path $logDir -Recurse -Force -ErrorAction SilentlyContinue
}

New-Item -ItemType Directory -Path $logDir | Out-Null

$services = @(
    @{
        Name = 'api'
        Label = 'Laravel API'
        Workdir = Join-Path $root 'backend'
        FilePath = 'php'
        Arguments = @('artisan', 'serve')
    },
    @{
        Name = 'reverb'
        Label = 'Laravel Reverb'
        Workdir = Join-Path $root 'backend'
        FilePath = 'php'
        Arguments = @('artisan', 'reverb:start')
    },
    @{
        Name = 'queue'
        Label = 'Laravel Queue'
        Workdir = Join-Path $root 'backend'
        FilePath = 'php'
        Arguments = @('artisan', 'queue:work')
    },
    @{
        Name = 'front'
        Label = 'Angular Frontend'
        Workdir = Join-Path $root 'front'
        FilePath = 'npm.cmd'
        Arguments = @('run', 'start')
    },
    @{
        Name = 'stripe'
        Label = 'Stripe Webhook'
        Workdir = $root
        FilePath = (Join-Path $root 'stripe.exe')
        Arguments = @('listen', '--forward-to', 'http://localhost:8000/api/stripe/webhook')
    }
)

$started = @()

function Stop-LocalInlineProcesses {
    param([array]$Processes, [string]$File)

    foreach ($item in $Processes) {
        if ($null -eq $item.pid) {
            continue
        }

        Stop-Process -Id $item.pid -Force -ErrorAction SilentlyContinue
    }

    if (Test-Path $File) {
        Remove-Item -Path $File -Force -ErrorAction SilentlyContinue
    }
}

trap {
    Write-Host "`nStopping local services..." -ForegroundColor Yellow
    Stop-LocalInlineProcesses -Processes $started -File $stateFile
    break
}

try {
    foreach ($service in $services) {
        $stdout = Join-Path $logDir "$($service.Name).out.log"
        $stderr = Join-Path $logDir "$($service.Name).err.log"

        New-Item -ItemType File -Path $stdout -Force | Out-Null
        New-Item -ItemType File -Path $stderr -Force | Out-Null

        $process = Start-Process `
            -FilePath $service.FilePath `
            -ArgumentList $service.Arguments `
            -WorkingDirectory $service.Workdir `
            -RedirectStandardOutput $stdout `
            -RedirectStandardError $stderr `
            -PassThru

        $started += [pscustomobject]@{
            name = $service.Name
            label = $service.Label
            pid = $process.Id
            stdout = $stdout
            stderr = $stderr
        }

        Start-Sleep -Milliseconds 250
    }

    $started | ConvertTo-Json | Set-Content -Path $stateFile -Encoding UTF8
    Write-Host "Local services started in this terminal. Press Ctrl+C to stop all of them." -ForegroundColor Green

    $positions = @{}
    foreach ($item in $started) {
        $positions[$item.stdout] = 0
        $positions[$item.stderr] = 0
    }

    while ($true) {
        foreach ($item in $started) {
            foreach ($stream in @(
                @{ Path = $item.stdout; Kind = 'OUT' },
                @{ Path = $item.stderr; Kind = 'ERR' }
            )) {
                $path = $stream.Path

                if (-not (Test-Path $path)) {
                    continue
                }

                $content = [System.IO.File]::ReadAllText($path)
                $position = [int]($positions[$path] ?? 0)

                if ($content.Length -gt $position) {
                    $delta = $content.Substring($position)
                    $positions[$path] = $content.Length

                    foreach ($line in ($delta -split "`r?`n")) {
                        if ([string]::IsNullOrWhiteSpace($line)) {
                            continue
                        }

                        Write-Host ("[{0}][{1}] {2}" -f $item.name.ToUpper(), $stream.Kind, $line)
                    }
                }
            }
        }

        $exited = @()
        foreach ($item in $started) {
            $proc = Get-Process -Id $item.pid -ErrorAction SilentlyContinue
            if (-not $proc) {
                $exited += $item
            }
        }

        if ($exited.Count -gt 0) {
            foreach ($item in $exited) {
                Write-Host ("[{0}] process exited." -f $item.name.ToUpper()) -ForegroundColor Yellow
            }

            throw "One or more local services exited."
        }

        Start-Sleep -Milliseconds 500
    }
} finally {
    Stop-LocalInlineProcesses -Processes $started -File $stateFile
}
