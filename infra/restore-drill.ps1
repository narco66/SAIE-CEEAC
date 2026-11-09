# Exercice de restauration (CONT-02, R14) : sauvegarde base + fichiers, restauration dans une base et un dossier vierges,
# contrôles de cohérence (volumes, empreintes SHA-256 des fichiers, contrainte d'exclusion, journal), mesure RPO/RTO.
# Usage : powershell -File infra/restore-drill.ps1 -Port 5433 -Database saie_ceeac -User saie
param([int]$Port = 5433, [string]$Database = 'saie_ceeac', [string]$User = 'saie', [string]$PgBin = 'C:\Program Files\PostgreSQL\18\bin')
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$work = Join-Path $env:TEMP ("saie-drill-" + (Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Force $work | Out-Null
$target = "${Database}_restore_drill"
function Q($db, $sql) { (& "$PgBin\psql.exe" -h 127.0.0.1 -p $Port -U $User -d $db -tAc $sql).Trim() }

$tables = 'users','contents','content_versions','campaigns','reservation_occurrences','documents','asset_versions','audit_logs','device_receipts'
$before = @{}; foreach ($t in $tables) { $before[$t] = Q $Database "select count(*) from $t" }
$lastWrite = Q $Database "select max(occurred_at) from audit_logs"

# 1. Sauvegarde
$t0 = Get-Date
& "$PgBin\pg_dump.exe" -h 127.0.0.1 -p $Port -U $User -Fc -f "$work\db.dump" $Database
robocopy (Join-Path $root 'backend\storage\app\private') "$work\files" /E /NFL /NDL /NJH /NJS | Out-Null
$backupSeconds = ((Get-Date) - $t0).TotalSeconds

# 2. Restauration dans un environnement vierge
$t1 = Get-Date
& "$PgBin\psql.exe" -h 127.0.0.1 -p $Port -U $User -d postgres -c "DROP DATABASE IF EXISTS $target;" | Out-Null
& "$PgBin\psql.exe" -h 127.0.0.1 -p $Port -U $User -d postgres -c "CREATE DATABASE $target TEMPLATE template0;" | Out-Null
& "$PgBin\pg_restore.exe" -h 127.0.0.1 -p $Port -U $User -d $target --no-owner "$work\db.dump"
robocopy "$work\files" "$work\restored-files" /E /NFL /NDL /NJH /NJS | Out-Null
$rtoSeconds = ((Get-Date) - $t1).TotalSeconds

# 3. Contrôles
$report = [ordered]@{ backup_s = [math]::Round($backupSeconds, 1); rto_s = [math]::Round($rtoSeconds, 1); last_write_before = $lastWrite; last_write_restored = (Q $target "select max(occurred_at) from audit_logs") }
foreach ($t in $tables) { $report["count_$t"] = "$($before[$t]) -> $(Q $target "select count(*) from $t")" }
$report['exclusion_constraint'] = Q $target "select count(*) from pg_constraint where conname = 'occurrences_no_overlap'"
$report['audit_trigger'] = Q $target "select count(*) from pg_trigger where tgname = 'audit_logs_append_only'"
$keys = (& "$PgBin\psql.exe" -h 127.0.0.1 -p $Port -U $User -d $target -tAc "select storage_key || '|' || sha256 from asset_versions where scan_status = 'ready' and sha256 is not null")
$ok = 0; $ko = 0
foreach ($line in $keys) { if (-not $line) { continue }; $k, $h = $line.Trim().Split('|'); $f = Join-Path "$work\restored-files" ($k -replace '/', '\'); if ((Test-Path $f) -and ((Get-FileHash $f -Algorithm SHA256).Hash.ToLower() -eq $h)) { $ok++ } else { $ko++ } }
$report['files_sha256_ok'] = $ok; $report['files_sha256_ko'] = $ko
& "$PgBin\psql.exe" -h 127.0.0.1 -p $Port -U $User -d postgres -c "DROP DATABASE $target;" | Out-Null
$report | ConvertTo-Json
