$repoPath = 'C:\xampp\htdocs\WMS_FENIX'
$logFile  = Join-Path $repoPath 'logs\auto_sync.log'

function Write-Log($msg) {
    $line = "[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $msg
    Add-Content -Path $logFile -Value $line
}

try {
    Set-Location $repoPath

    git add -A *> $null

    $staged = git diff --cached --name-only
    if ([string]::IsNullOrWhiteSpace($staged)) {
        Write-Log 'Sin cambios, nada que sincronizar.'
        exit 0
    }

    $timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    $commitOutput = git commit -m "auto-sync: $timestamp" 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Log "ERROR en commit: $commitOutput"
        exit 1
    }

    $pushOutput = git push origin main 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Log "ERROR en push: $pushOutput"
        exit 1
    }

    Write-Log "Commit + push realizado. Archivos: $($staged -join ', ')"
}
catch {
    Write-Log "ERROR inesperado: $($_.Exception.Message)"
}
