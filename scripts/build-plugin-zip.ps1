# Builds public/plugin-update/s35-wp-hub.zip from plugin/s35-wp-hub/.
# Renames an existing s35-wp-hub.zip to s35-wp-hub-{previous-version}.zip using .last-packaged-version.

$ErrorActionPreference = "Stop"
$root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$pluginDir = Join-Path $root "plugin\s35-wp-hub"
$outDir = Join-Path $root "public\plugin-update"
$zipPath = Join-Path $outDir "s35-wp-hub.zip"
$stateFile = Join-Path $outDir ".last-packaged-version"
$mainFile = Join-Path $pluginDir "s35-wp-hub.php"

if (-not (Test-Path $pluginDir)) {
    Write-Error "Plugin folder not found: $pluginDir"
}
if (-not (Test-Path $mainFile)) {
    Write-Error "Main plugin file not found: $mainFile"
}

$content = Get-Content -LiteralPath $mainFile -Raw
if ($content -notmatch "define\s*\(\s*'S35_WP_HUB_VERSION'\s*,\s*'([^']+)'") {
    Write-Error "Could not parse S35_WP_HUB_VERSION in s35-wp-hub.php"
}
$currentVersion = $Matches[1]

New-Item -ItemType Directory -Force -Path $outDir | Out-Null

$lastVersion = ""
if (Test-Path -LiteralPath $stateFile) {
    $lastVersion = (Get-Content -LiteralPath $stateFile -Raw).Trim()
}

if (Test-Path -LiteralPath $zipPath) {
    if ($lastVersion) {
        $archived = Join-Path $outDir "s35-wp-hub-$lastVersion.zip"
    }
    else {
        $archived = Join-Path $outDir ("s35-wp-hub-backup-" + (Get-Date -Format "yyyyMMdd-HHmmss") + ".zip")
    }
    if (Test-Path -LiteralPath $archived) {
        $suffix = if ($lastVersion) { $lastVersion } else { "backup" }
        $archived = Join-Path $outDir ("s35-wp-hub-$suffix-" + (Get-Date -Format "yyyyMMddHHmmss") + ".zip")
    }
    Move-Item -LiteralPath $zipPath -Destination $archived -Force
    Write-Host ("Archived previous zip -> " + (Split-Path $archived -Leaf))
}

if (Test-Path -LiteralPath $zipPath) {
    Remove-Item -LiteralPath $zipPath -Force
}

Compress-Archive -Path $pluginDir -DestinationPath $zipPath -CompressionLevel Optimal -Force
Set-Content -LiteralPath $stateFile -Value $currentVersion -NoNewline
Write-Host "Built $zipPath (S35_WP_HUB_VERSION $currentVersion)"
