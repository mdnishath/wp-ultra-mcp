# Mirror the plugin into the Local "wp-connector" site so it can be activated/tested.
$ErrorActionPreference = 'Stop'
$src = 'E:\wp-connector\wp-ultra-mcp'
$dst = 'C:\Users\nisha\Local Sites\wp-connector\app\public\wp-content\plugins\wp-ultra-mcp'
New-Item -ItemType Directory -Force -Path $dst | Out-Null
robocopy $src $dst /MIR /XD tests .git node_modules /NFL /NDL /NJH /NJS /NP | Out-Null
Write-Host "Deployed to $dst"
