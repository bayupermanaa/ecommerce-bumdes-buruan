[CmdletBinding()]
param(
    [string]$ProjectRoot = (Split-Path -Parent $PSScriptRoot),
    [string]$BackupRoot,
    [datetime]$BackupDate = (Get-Date)
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Require-EnvironmentValue {
    param([Parameter(Mandatory)][string]$Name)

    $value = [Environment]::GetEnvironmentVariable($Name, 'Process')

    if ([string]::IsNullOrWhiteSpace($value)) {
        throw "Environment variable $Name wajib tersedia pada proses backup."
    }

    return $value
}

function Write-Utf8NoBom {
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][string]$Content
    )

    [System.IO.File]::WriteAllText($Path, $Content, [System.Text.UTF8Encoding]::new($false))
}

function Get-FileMetadata {
    param([Parameter(Mandatory)][string]$Path)

    $item = Get-Item -LiteralPath $Path

    if ($item.Length -le 0) {
        throw "File backup kosong: $Path"
    }

    return [ordered]@{
        file = $item.Name
        bytes = $item.Length
        sha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash
    }
}

function Test-ZipArchive {
    param([Parameter(Mandatory)][string]$Path)

    if (-not (Test-Path -LiteralPath $Path) -or (Get-Item -LiteralPath $Path).Length -le 0) {
        throw "Arsip storage tidak tersedia atau kosong: $Path"
    }

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [System.IO.Compression.ZipFile]::OpenRead($Path)

    try {
        if ($archive.Entries.Count -eq 0) {
            throw "Arsip storage tidak memiliki entri: $Path"
        }
    }
    finally {
        $archive.Dispose()
    }
}

function New-StorageArchive {
    param(
        [Parameter(Mandatory)][string]$Source,
        [Parameter(Mandatory)][string]$StagingRoot,
        [Parameter(Mandatory)][string]$Destination
    )

    if (-not (Test-Path -LiteralPath $Source -PathType Container)) {
        throw "Direktori storage tidak ditemukan: $Source"
    }

    New-Item -ItemType Directory -Path $StagingRoot | Out-Null
    $sourceItems = @(Get-ChildItem -LiteralPath $Source -Force)

    if ($sourceItems.Count -eq 0) {
        # Keeps an intentionally empty storage directory representable in a valid ZIP archive.
        [System.IO.File]::WriteAllText((Join-Path $StagingRoot '.backup-directory-placeholder'), '', [System.Text.UTF8Encoding]::new($false))
    }
    else {
        Copy-Item -Path (Join-Path $Source '*') -Destination $StagingRoot -Recurse -Force
    }

    Compress-Archive -LiteralPath $StagingRoot -DestinationPath $Destination -CompressionLevel Optimal
    Test-ZipArchive -Path $Destination
}

$projectRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path
$backupRoot = if ($BackupRoot) { $BackupRoot } else { Join-Path $projectRoot 'backup/production' }
$backupRoot = [System.IO.Path]::GetFullPath($backupRoot)
$dateFolder = $BackupDate.ToUniversalTime().ToString('yyyy-MM-dd')
$destination = Join-Path $backupRoot $dateFolder

if (Test-Path -LiteralPath $destination) {
    throw "Backup untuk tanggal $dateFolder sudah ada. Backup lama tidak akan ditimpa."
}

$mysqlDump = Require-EnvironmentValue -Name 'MYSQLDUMP_PATH'
$dbHost = Require-EnvironmentValue -Name 'DB_HOST'
$dbPort = Require-EnvironmentValue -Name 'DB_PORT'
$dbName = Require-EnvironmentValue -Name 'DB_DATABASE'
$dbUser = Require-EnvironmentValue -Name 'DB_USERNAME'
$dbPassword = Require-EnvironmentValue -Name 'DB_PASSWORD'

if (-not (Test-Path -LiteralPath $mysqlDump -PathType Leaf)) {
    throw 'MYSQLDUMP_PATH tidak menunjuk executable mysqldump yang dapat dibaca.'
}

$privateStorage = Join-Path $projectRoot 'storage/app/private'
$publicStorage = Join-Path $projectRoot 'storage/app/public'
$partial = Join-Path $backupRoot ('.partial-' + [guid]::NewGuid().ToString('N'))
$defaultsFile = Join-Path $partial 'mysql-client.cnf'
$timestamp = $BackupDate.ToUniversalTime().ToString('o')

try {
    New-Item -ItemType Directory -Path $partial -Force | Out-Null

    # Credentials are passed through an ephemeral defaults file, not command-line arguments.
    Write-Utf8NoBom -Path $defaultsFile -Content ((@(
        '[client]'
        "host=$dbHost"
        "port=$dbPort"
        "user=$dbUser"
        "password=$dbPassword"
    ) -join [Environment]::NewLine) + [Environment]::NewLine)

    $databaseFile = Join-Path $partial 'database.sql'
    $dumpArguments = @(
        "--defaults-extra-file=$defaultsFile",
        '--single-transaction',
        '--routines',
        '--triggers',
        '--events',
        '--default-character-set=utf8mb4',
        '--set-gtid-purged=OFF',
        '--no-tablespaces',
        "--result-file=$databaseFile",
        '--databases',
        $dbName
    )

    & $mysqlDump @dumpArguments

    if ($LASTEXITCODE -ne 0) {
        throw "mysqldump gagal dengan exit code $LASTEXITCODE."
    }

    if (-not (Test-Path -LiteralPath $databaseFile) -or (Get-Item -LiteralPath $databaseFile).Length -le 0) {
        throw 'mysqldump tidak menghasilkan database.sql yang valid.'
    }

    $privateArchive = Join-Path $partial 'private-storage.zip'
    $publicArchive = Join-Path $partial 'public-storage.zip'
    New-StorageArchive -Source $privateStorage -StagingRoot (Join-Path $partial 'private-storage') -Destination $privateArchive
    New-StorageArchive -Source $publicStorage -StagingRoot (Join-Path $partial 'public-storage') -Destination $publicArchive

    Remove-Item -LiteralPath $defaultsFile -Force

    $manifest = [ordered]@{
        schema_version = 1
        created_at_utc = $timestamp
        database = Get-FileMetadata -Path $databaseFile
        private_storage = Get-FileMetadata -Path $privateArchive
        public_storage = Get-FileMetadata -Path $publicArchive
        notes = 'Credentials, APP_KEY, and source storage paths are intentionally excluded.'
    }

    Write-Utf8NoBom -Path (Join-Path $partial 'manifest.json') -Content ($manifest | ConvertTo-Json -Depth 4)

    Move-Item -LiteralPath $partial -Destination $destination
    Write-Output "Backup berhasil dibuat: $destination"
}
catch {
    if (Test-Path -LiteralPath $partial) {
        Remove-Item -LiteralPath $partial -Recurse -Force
    }

    throw
}
