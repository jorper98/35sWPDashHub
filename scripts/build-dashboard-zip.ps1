# Builds dist/s35-wp-hub-dashboard-v{VERSION}.zip via PHP (requires ext-zip).
# Pass-through: --no-vendor

$ErrorActionPreference = "Stop"
$root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$phpArgs = @("scripts/build-dashboard-zip.php")
if ($args.Count -gt 0) {
    $phpArgs += $args
}
Push-Location $root
try {
    & php @phpArgs
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}
finally {
    Pop-Location
}
