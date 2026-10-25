param([string]$Dotnet = 'dotnet')
$ErrorActionPreference = 'Stop'
$project = Join-Path $PSScriptRoot 'src/OsonPos.PrintAgent/OsonPos.PrintAgent.csproj'
$output = Join-Path $PSScriptRoot 'artifacts/app'
& $Dotnet publish $project -c Release -r win-x64 --self-contained true -p:EnableCompressionInSingleFile=true -o $output
if ($LASTEXITCODE -ne 0) { throw 'Agent publish failed.' }
$source = Join-Path $output 'OsonPOSPrintAgent.exe'
$installer = Join-Path $PSScriptRoot 'artifacts/OsonPOSPrintAgentSetup.exe'
Copy-Item -LiteralPath $source -Destination $installer -Force
$checksum = Get-FileHash -LiteralPath $installer -Algorithm SHA256
Set-Content -LiteralPath ($installer + '.sha256') -Value ($checksum.Hash.ToLowerInvariant() + '  ' + [IO.Path]::GetFileName($installer)) -Encoding ascii
$checksum | Format-List
Write-Output $installer
