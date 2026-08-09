$ErrorActionPreference = 'Stop'

# Resolve PHP: env override > PATH > Local by Flywheel lightning-services (any version).
$PHP = $env:WPULTRA_PHP
if (-not $PHP) {
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { $PHP = $cmd.Source }
}
if (-not $PHP) {
    $services = Join-Path $env:APPDATA 'Local\lightning-services'
    if (Test-Path $services) {
        $candidate = Get-ChildItem $services -Directory -Filter 'php-*' |
            Sort-Object Name -Descending |
            ForEach-Object { Join-Path $_.FullName 'bin\win64\php.exe' } |
            Where-Object { Test-Path $_ } |
            Select-Object -First 1
        if ($candidate) { $PHP = $candidate }
    }
}
if (-not $PHP) { Write-Error 'No PHP binary found. Set $env:WPULTRA_PHP or add php to PATH.'; exit 1 }

$fail = 0
Get-ChildItem (Join-Path $PSScriptRoot '*.test.php') | ForEach-Object {
    Write-Host "== $($_.Name) =="
    & $PHP $_.FullName
    if ($LASTEXITCODE -ne 0) { $fail++ }
}
if ($fail -gt 0) { Write-Error "$fail test file(s) failed"; exit 1 } else { Write-Host "ALL TEST FILES PASSED" }
