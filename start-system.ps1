$ErrorActionPreference = 'Stop'
$appRoot = $PSScriptRoot
$php = 'C:\xampp\php\php.exe'
$artisan = Join-Path $appRoot 'artisan'
$router = Join-Path $appRoot 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
$logs = Join-Path $appRoot 'storage\logs'
if (!(Test-Path $php) -or !(Test-Path $router) -or !(Test-Path (Join-Path $appRoot '.env'))) {
    throw 'PHP, installed dependencies, or local .env settings are missing.'
}
New-Item -ItemType Directory -Path $logs -Force | Out-Null
$services = @(
    @{ Name='web'; Args=@('-S','0.0.0.0:8000',('"'+$router+'"')); Directory=(Join-Path $appRoot 'public'); Match=[regex]::Escape($router) },
    @{ Name='queue'; Args=@(('"'+$artisan+'"'),'queue:work','--tries=3','--timeout=540'); Directory=$appRoot; Match=([regex]::Escape($artisan)+'"?\s+queue:work') },
    @{ Name='scheduler'; Args=@(('"'+$artisan+'"'),'schedule:work'); Directory=$appRoot; Match=([regex]::Escape($artisan)+'"?\s+schedule:work') }
)
foreach ($service in $services) {
    $running = @(Get-CimInstance Win32_Process -Filter "name = 'php.exe'" | Where-Object { $_.CommandLine -match $service.Match })
    if ($running.Count) {
        Write-Host "$($service.Name): already running (PID $($running[0].ProcessId))"
        continue
    }
    if ($service.Name -eq 'web') {
        $listener = Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue
        if ($listener) { throw 'Port 8000 is already in use by another server. Close it before starting FurniCraft.' }
    }
    $process = Start-Process -FilePath $php -ArgumentList $service.Args -WorkingDirectory $service.Directory -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logs ($service.Name+'-launcher.log')) -RedirectStandardError (Join-Path $logs ($service.Name+'-launcher-error.log')) -PassThru
    Start-Sleep -Milliseconds 700
    if ($process.HasExited) { throw "$($service.Name) failed to start. Check storage\logs\$($service.Name)-launcher-error.log." }
    Write-Host "$($service.Name): started (PID $($process.Id))"
}
$ready = $false
for ($attempt = 0; $attempt -lt 10; $attempt++) {
    try {
        $page = Invoke-WebRequest 'http://127.0.0.1:8000/login' -UseBasicParsing -TimeoutSec 5
        if ($page.StatusCode -eq 200 -and $page.Content -match 'name="password"') { $ready = $true; break }
    } catch { Start-Sleep -Milliseconds 500 }
}
if (!$ready) { throw 'Services started, but the login page did not pass the readiness check. Inspect storage\logs.' }
Write-Host 'FurniCraft is ready: http://127.0.0.1:8000/login'
