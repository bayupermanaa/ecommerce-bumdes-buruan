[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Write-Utf8NoBom {
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][string]$Content
    )

    [System.IO.File]::WriteAllText($Path, $Content, [System.Text.UTF8Encoding]::new($false))
}

$workspace = Join-Path ([System.IO.Path]::GetTempPath()) ('bumdes-backup-fixture-' + [guid]::NewGuid().ToString('N'))
$previous = @{}
$variables = 'MYSQLDUMP_PATH', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'

try {
    foreach ($name in $variables) {
        $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
    }

    $privatePath = Join-Path $workspace 'project/storage/app/private'
    $publicPath = Join-Path $workspace 'project/storage/app/public'
    New-Item -ItemType Directory -Path $privatePath, $publicPath -Force | Out-Null
    Write-Utf8NoBom -Path (Join-Path $privatePath 'private-proof.txt') -Content 'private fixture'
    Write-Utf8NoBom -Path (Join-Path $publicPath 'public-image.txt') -Content 'public fixture'

    $fakeDump = Join-Path $workspace 'fake-mysqldump.ps1'
    @'
param([Parameter(ValueFromRemainingArguments = $true)][string[]]$Arguments)
$result = $Arguments | Where-Object { $_ -like '--result-file=*' } | Select-Object -First 1
if (-not $result) { exit 12 }
$path = $result.Substring('--result-file='.Length)
Set-Content -LiteralPath $path -Value @('-- MySQL dump fixture', 'CREATE DATABASE `fixture`;', 'USE `fixture`;', 'CREATE TABLE `example` (`id` int);') -Encoding UTF8
exit 0
'@ | ForEach-Object { Write-Utf8NoBom -Path $fakeDump -Content $_ }

    [Environment]::SetEnvironmentVariable('MYSQLDUMP_PATH', $fakeDump, 'Process')
    [Environment]::SetEnvironmentVariable('DB_HOST', 'fixture-host', 'Process')
    [Environment]::SetEnvironmentVariable('DB_PORT', '3306', 'Process')
    [Environment]::SetEnvironmentVariable('DB_DATABASE', 'fixture_database', 'Process')
    [Environment]::SetEnvironmentVariable('DB_USERNAME', 'fixture_user', 'Process')
    [Environment]::SetEnvironmentVariable('DB_PASSWORD', 'fixture-secret', 'Process')

    $script = Join-Path $PSScriptRoot 'backup-production.ps1'
    $backupRoot = Join-Path $workspace 'backups/production'
    $date = [datetime]'2026-10-31T09:00:00Z'
    & $script -ProjectRoot (Join-Path $workspace 'project') -BackupRoot $backupRoot -BackupDate $date

    $backup = Join-Path $backupRoot '2026-10-31'
    foreach ($file in 'database.sql', 'private-storage.zip', 'public-storage.zip', 'manifest.json') {
        if (-not (Test-Path -LiteralPath (Join-Path $backup $file))) {
            throw "Fixture backup tidak memiliki $file."
        }
    }

    $manifest = Get-Content -LiteralPath (Join-Path $backup 'manifest.json') -Raw | ConvertFrom-Json
    if ($manifest.database.bytes -le 0 -or $manifest.private_storage.bytes -le 0 -or $manifest.public_storage.bytes -le 0) {
        throw 'Manifest fixture berisi ukuran backup tidak valid.'
    }

    foreach ($entry in @($manifest.database, $manifest.private_storage, $manifest.public_storage)) {
        $actualHash = (Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $backup $entry.file)).Hash
        if ($actualHash -ne $entry.sha256) {
            throw "Checksum fixture tidak cocok untuk $($entry.file)."
        }
    }

    if ((Get-Content -LiteralPath (Join-Path $backup 'manifest.json') -Raw) -match 'fixture-secret') {
        throw 'Secret fixture bocor ke manifest.'
    }

    $failedAsExpected = $false
    try {
        & $script -ProjectRoot (Join-Path $workspace 'project') -BackupRoot $backupRoot -BackupDate $date
    }
    catch {
        $failedAsExpected = $true
    }

    if (-not $failedAsExpected) {
        throw 'Backup lama dapat tertimpa.'
    }

    $failingDump = Join-Path $workspace 'failing-mysqldump.ps1'
    'exit 9' | Set-Content -LiteralPath $failingDump -Encoding UTF8
    [Environment]::SetEnvironmentVariable('MYSQLDUMP_PATH', $failingDump, 'Process')
    $failedDate = $date.AddDays(1)
    $failedBackup = Join-Path $backupRoot $failedDate.ToString('yyyy-MM-dd')
    $failedDumpAsExpected = $false

    try {
        & $script -ProjectRoot (Join-Path $workspace 'project') -BackupRoot $backupRoot -BackupDate $failedDate
    }
    catch {
        $failedDumpAsExpected = $true
    }

    if (-not $failedDumpAsExpected -or (Test-Path -LiteralPath $failedBackup)) {
        throw 'Dump gagal menghasilkan backup parsial yang dianggap berhasil.'
    }

    Write-Output 'Backup fixture test passed.'
}
finally {
    foreach ($name in $variables) {
        [Environment]::SetEnvironmentVariable($name, $previous[$name], 'Process')
    }

    if (Test-Path -LiteralPath $workspace) {
        Remove-Item -LiteralPath $workspace -Recurse -Force
    }
}
