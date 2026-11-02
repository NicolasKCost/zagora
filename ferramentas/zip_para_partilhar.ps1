# Gera um ZIP do codigo SEM segredos, pra mandar pra outra pessoa ou outra IA analisar.
# Fica de fora: config.php (token, chave da IA, senha do banco), logs, backups, .git e zips antigos.
# Antes de fechar, procura tokens do Telegram e chaves da Cerebras em TODOS os arquivos; se achar, nao gera o ZIP.
#   .\ferramentas\zip_para_partilhar.ps1        -> cria zagora-codigo-AAAAMMDD.zip na Area de Trabalho

$ErrorActionPreference = 'Stop'
$Projeto = Split-Path $PSScriptRoot -Parent
$tmp = Join-Path $env:TEMP 'zagora_partilha'
$destino = Join-Path ([Environment]::GetFolderPath('Desktop')) ('zagora-codigo-' + (Get-Date -Format 'yyyyMMdd') + '.zip')

if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
robocopy $Projeto $tmp /E /XF config.php *.log *.bak *.zip *.gz *.tar .mensagem_commit /XD .git backup_servidor backups /NFL /NDL /NJH /NJS /NP | Out-Null
Get-ChildItem $tmp -Recurse -Force -Filter 'config.php' | Remove-Item -Force   # garantia extra

# Procura segredos que tenham ficado em algum outro arquivo (so mostra o NOME do arquivo, nunca o valor)
$padroes = @(
    '\b\d{8,10}:AA[A-Za-z0-9_-]{30,}\b',   # token de bot do Telegram
    '\bcsk-[A-Za-z0-9]{20,}\b'             # chave da Cerebras
)
$suspeitos = Get-ChildItem $tmp -Recurse -File | Where-Object { Select-String -Path $_.FullName -Pattern $padroes -Quiet }
if ($suspeitos) {
    Write-Host 'Achei algo com cara de token/chave nestes arquivos - o ZIP NAO foi gerado:' -ForegroundColor Red
    $suspeitos | ForEach-Object { Write-Host ('  ' + $_.FullName.Substring($tmp.Length + 1)) }
    Remove-Item $tmp -Recurse -Force
    exit 1
}

if (Test-Path $destino) { Remove-Item $destino -Force }
Compress-Archive -Path (Join-Path $tmp '*') -DestinationPath $destino
Remove-Item $tmp -Recurse -Force
Write-Host "ZIP sem segredos criado: $destino" -ForegroundColor Green
