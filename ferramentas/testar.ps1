# Roda os testes do Zágora no servidor, com os arquivos DESTA pasta (não mexe no site).
#   .\ferramentas\testar.ps1           → tudo, com IA (uns 2 minutos)
#   .\ferramentas\testar.ps1 -Rapido   → só as regras sem IA (segundos)
param([switch]$Rapido)

. "$PSScriptRoot\comum.ps1"

Send-Stage
$passou = Invoke-Testes -Rapido:$Rapido
Remove-Stage
if (-not $passou) { exit 1 }
