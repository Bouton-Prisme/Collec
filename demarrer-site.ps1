param([switch]$SansNavigateur)

$ErrorActionPreference = 'Stop'
$siteRoot = $PSScriptRoot
$port = 8080
$url = "http://127.0.0.1:$port/home.html"
$phpCommand = Get-Command php -ErrorAction SilentlyContinue
$phpPath = if ($phpCommand) { $phpCommand.Source } else { 'C:\php\php.exe' }

if (-not (Test-Path -LiteralPath $phpPath)) {
    throw 'PHP est introuvable. Installez PHP et ajoutez-le au PATH.'
}

# Reutiliser uniquement le serveur PHP de ce dossier.
$listener = Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue
if ($listener) {
    $server = Get-CimInstance Win32_Process -Filter "ProcessId = $($listener[0].OwningProcess)"
    if ($server.Name -ne 'php.exe' -or -not $server.CommandLine.Contains($siteRoot)) {
        throw "Le port $port est utilise par un autre programme. Liberez ce port puis relancez."
    }
} else {
    $server = Start-Process -FilePath $phpPath -ArgumentList @('-d', 'upload_max_filesize=5M', '-d', 'post_max_size=8M', '-S', "127.0.0.1:$port", '-t', ('"' + $siteRoot + '"')) -WorkingDirectory $siteRoot -WindowStyle Hidden -PassThru
}

$ready = $false
for ($attempt = 0; $attempt -lt 30; $attempt++) {
    try {
        $response = Invoke-WebRequest -Uri "http://127.0.0.1:$port/admin.php" -UseBasicParsing -TimeoutSec 2
        if ($response.StatusCode -eq 200 -and $response.Headers['Content-Type'] -like 'text/html*' -and $response.Content -notmatch '<\?php') {
            $ready = $true
            break
        }
    } catch {
        Start-Sleep -Milliseconds 200
    }
}
if (-not $ready) {
    throw "Le serveur PHP n'a pas pu demarrer correctement sur le port $port."
}

Write-Host "Site : $url"
Write-Host "Administration : http://127.0.0.1:$port/admin.php"
if (-not $SansNavigateur) {
    Start-Process $url
}
