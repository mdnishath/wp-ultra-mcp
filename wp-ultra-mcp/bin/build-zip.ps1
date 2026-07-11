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

# Verify: no backslash entry names (Linux PHP ZipArchive treats them as literal
# filename chars -> flat junk files -> activation fatal; broke v0.13.0 AND v0.28.0),
# and the main plugin file is present under the expected forward-slash path.
$check = [System.IO.Compression.ZipFile]::OpenRead($zip)
try {
    $bad = @($check.Entries | Where-Object { $_.FullName -like '*\*' })
    $main = @($check.Entries | Where-Object { $_.FullName -eq 'wp-ultra-mcp/wp-ultra-mcp.php' })
    $count = $check.Entries.Count
} finally {
    $check.Dispose()
}
if ($bad.Count -gt 0) {
    Remove-Item -Force $zip
    throw "ZIP REJECTED: $($bad.Count) entries use backslash separators (e.g. '$($bad[0].FullName)'). Never publish this zip."
}
if ($main.Count -ne 1) {
    Remove-Item -Force $zip
    throw "ZIP REJECTED: wp-ultra-mcp/wp-ultra-mcp.php entry missing."
}
Write-Host "Built $zip ($count entries, separators verified)"
