# Produce a release zip (vendor bundled, tests excluded).
# Uses .NET ZipArchive directly with FORWARD-SLASH entry names: PowerShell 5.1's
# Compress-Archive writes backslash separators, which Linux PHP ZipArchive treats
# as literal filename characters (broke the v0.13.0 remote install).
$ErrorActionPreference = 'Stop'
$src = 'E:\wp-connector\wp-ultra-mcp'
$zip = 'E:\wp-connector\wp-ultra-mcp.zip'
$exclude = @('.git', 'node_modules')

if (Test-Path $zip) { Remove-Item -Force $zip }
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$archive = [System.IO.Compression.ZipFile]::Open($zip, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    $srcRoot = (Get-Item $src).FullName
    Get-ChildItem -Path $src -Recurse -File | ForEach-Object {
        $rel = $_.FullName.Substring($srcRoot.Length + 1)
        $parts = $rel -split '[\\/]'
        if ($exclude | Where-Object { $parts -contains $_ }) { return }
        $entryName = 'wp-ultra-mcp/' + ($rel -replace '\\', '/')
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $_.FullName, $entryName) | Out-Null
    }
} finally {
    $archive.Dispose()
}
Write-Host "Built $zip"
