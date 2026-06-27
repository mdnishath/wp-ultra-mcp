# Produce a release zip (vendor bundled, tests excluded).
$ErrorActionPreference = 'Stop'
$src = 'E:\wp-connector\wp-ultra-mcp'
$stage = "$env:TEMP\wp-ultra-mcp"
$zip = 'E:\wp-connector\wp-ultra-mcp.zip'
if (Test-Path $stage) { Remove-Item -Recurse -Force $stage }
New-Item -ItemType Directory -Force -Path $stage | Out-Null
robocopy $src "$stage\wp-ultra-mcp" /MIR /XD .git node_modules /NFL /NDL /NJH /NJS /NP | Out-Null
if (Test-Path $zip) { Remove-Item -Force $zip }
Compress-Archive -Path "$stage\wp-ultra-mcp" -DestinationPath $zip
Write-Host "Built $zip"
