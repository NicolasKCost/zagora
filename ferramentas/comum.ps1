# Funções usadas pelo testar.ps1 e pelo deploy.ps1 (não rodar direto).
# Precisa do atalho SSH "hostinger" configurado em ~/.ssh/config.

$ErrorActionPreference = 'Stop'
$Projeto = Split-Path $PSScriptRoot -Parent
$Servidor = 'hostinger'
$Stage = 'zagora_stage'   # cópia temporária no servidor, em ~/zagora_stage (fora do public_html)

# Arquivos que vão pro site (public_html): PHP da raiz (menos config) + .htaccess + migrations
function Get-ArquivosSite {
    $php = Get-ChildItem $Projeto -Filter *.php -File | Where-Object { $_.Name -notin @('config.php', 'config.example.php') }
    $outros = @(Get-Item (Join-Path $Projeto '.htaccess') -Force) + @(Get-ChildItem $Projeto -Filter 'migration_*.sql' -File)
    return @($php + $outros | ForEach-Object Name)
}

function Invoke-Servidor([string]$comando) {
    ssh -o BatchMode=yes $Servidor $comando | Out-Host
    if ($LASTEXITCODE -ne 0) { throw "Comando no servidor falhou (código $LASTEXITCODE)" }
}

# Tenta de novo quando a conexão cai (a Hostinger às vezes recusa conexões seguidas)
function Invoke-ComRetentativa([scriptblock]$acao, [string]$erro) {
    for ($i = 1; $i -le 4; $i++) {
        & $acao
        if ($LASTEXITCODE -eq 0) { return }
        if ($i -lt 4) { Write-Host "  (conexão falhou, tentando de novo em $($i * 15)s...)"; Start-Sleep ($i * 15) }
    }
    throw $erro
}

# Copia o projeto local pra ~/zagora_stage no servidor, com o config.php de produção (link, nunca copiado).
# Vai tudo num pacote só (1 scp + 1 ssh) — várias conexões seguidas faziam a Hostinger recusar.
function Send-Stage {
    Write-Host "→ Enviando arquivos pra cópia de teste no servidor..."
    $raiz = @(Get-ArquivosSite) + @('config.example.php', '.gitignore', 'zagora_handoff_claude_code.md') |
        Where-Object { Test-Path (Join-Path $Projeto $_) }
    $pacote = Join-Path ([IO.Path]::GetTempPath()) 'zagora_stage.tar'
    Push-Location $Projeto
    try { tar -cf $pacote @raiz testes ferramentas site } finally { Pop-Location }
    if ($LASTEXITCODE -ne 0) { throw "Falhou ao montar o pacote" }

    Invoke-ComRetentativa { scp -q -o BatchMode=yes $pacote "${Servidor}:zagora_stage.tar" } "Falhou ao enviar os arquivos"
    Invoke-ComRetentativa {
        ssh -o BatchMode=yes $Servidor "rm -rf ~/$Stage && mkdir -p ~/$Stage && tar -xf ~/zagora_stage.tar -C ~/$Stage && rm ~/zagora_stage.tar && ln -s ~/domains/zagora.space/public_html/config.php ~/$Stage/config.php" | Out-Host
    } "Falhou ao abrir o pacote no servidor"
    Remove-Item $pacote -ErrorAction SilentlyContinue
}

# Confere sintaxe de todo PHP e roda os testes na cópia. Devolve $true se passou.
function Invoke-Testes([switch]$Rapido) {
    $flag = if ($Rapido) { '--rapido' } else { '' }
    Write-Host "→ Rodando os testes$(if ($Rapido) { ' (rápido, sem IA)' } else { ' (completo, com IA — uns 2 minutos)' })..."
    # Out-Host: a saída do ssh vai pra tela (senão viraria parte do valor devolvido pela função)
    ssh -o BatchMode=yes $Servidor "cd ~/$Stage && for f in *.php testes/*.php ferramentas/*.php site/admin/*.php; do php -l `$f >/dev/null || { echo ERRO de sintaxe em `$f; exit 1; }; done && php testes/rodar.php $flag" | Out-Host
    return ($LASTEXITCODE -eq 0)
}

function Remove-Stage {
    ssh -o BatchMode=yes $Servidor "rm -rf ~/$Stage" | Out-Null
}
