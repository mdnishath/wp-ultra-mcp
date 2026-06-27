$ErrorActionPreference = 'Stop'
$PHP = 'C:\Users\nisha\AppData\Roaming\Local\lightning-services\php-8.2.30+1\bin\win64\php.exe'
$fail = 0
Get-ChildItem 'E:\wp-connector\tests\*.test.php' | ForEach-Object {
    Write-Host "== $($_.Name) =="
    & $PHP $_.FullName
    if ($LASTEXITCODE -ne 0) { $fail++ }
}
if ($fail -gt 0) { Write-Error "$fail test file(s) failed"; exit 1 } else { Write-Host "ALL TEST FILES PASSED" }
