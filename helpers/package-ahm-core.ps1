<#
.SYNOPSIS
    Packages C:\laragon\www\ahm-core into assets/plugins/ahm-core.zip.

.DESCRIPTION
    Automates clean distribution packaging of the proprietary AHM Core plugin,
    ensuring standard outer folder hierarchy (ahm-core/) and excluding .git
    metadata, ready for ingestion by setup.bat and WordPress installer.
#>

$ErrorActionPreference = 'Stop'

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$repoRoot  = Split-Path -Parent $scriptDir

$pluginSource   = 'C:\laragon\www\ahm-core'
$zipDestination = Join-Path $repoRoot 'assets\plugins\ahm-core.zip'
$stagingBase    = Join-Path $repoRoot '.tmp\ahm-core-build'
$stagingDir     = Join-Path $stagingBase 'ahm-core'

Write-Host "Preparing staging environment..."
if (Test-Path $stagingBase) {
    Remove-Item $stagingBase -Recurse -Force
}
New-Item -ItemType Directory -Path $stagingDir -Force | Out-Null

Write-Host "Copying plugin distribution files..."
Copy-Item (Join-Path $pluginSource 'ahm-core.php') $stagingDir -Force
Copy-Item (Join-Path $pluginSource 'uninstall.php') $stagingDir -Force
Copy-Item (Join-Path $pluginSource 'includes') $stagingDir -Recurse -Force
Copy-Item (Join-Path $pluginSource 'assets') $stagingDir -Recurse -Force
if (Test-Path (Join-Path $pluginSource '.github')) {
    Copy-Item (Join-Path $pluginSource '.github') $stagingDir -Recurse -Force
}

Write-Host "Compressing to $zipDestination..."
if (Test-Path $zipDestination) {
    Remove-Item $zipDestination -Force
}

Compress-Archive -Path $stagingDir -DestinationPath $zipDestination -CompressionLevel Optimal

Write-Host "Cleaning up staging directory..."
Remove-Item $stagingBase -Recurse -Force

$zipItem = Get-Item $zipDestination
Write-Host "Package successfully created: $zipDestination ($($zipItem.Length) bytes)"
