# Sobe o Zágora pro ar com segurança:
#   1) roda os testes no servidor (se falhar, NÃO sobe nada)
#   2) faz backup dos arquivos atuais do site em ~/backups/<data>
#   3) troca os arquivos (cada PHP é conferido antes de substituir o antigo)
#   4) registra a versão no git do servidor (~/zagora_repo), com a mensagem que você passar
#   5) confere se o site continua respondendo
#
# Uso:  .\ferramentas\deploy.ps1 -Mensagem "o que mudou"
#       .\ferramentas\deploy.ps1 -Mensagem "..." -SoTestesRapidos   (pula os testes com IA)
#
# Migration nova (migration_*.sql)? O deploy só COPIA o arquivo — rodar no banco continua sendo um passo à parte.
param(
    [Parameter(Mandatory = $true)][string]$Mensagem,
    [switch]$SoTestesRapidos,
    [switch]$CoautorClaude
)

. "$PSScriptRoot\comum.ps1"

Send-Stage
if (-not (Invoke-Testes -Rapido:$SoTestesRapidos)) {
    Remove-Stage
    Write-Host "`n❌ Testes falharam — nada foi enviado pro site." -ForegroundColor Red
    exit 1
}

# Mensagem do commit vai por arquivo (evita problema de aspas no ssh)
$textoCommit = $Mensagem
if ($CoautorClaude) { $textoCommit += "`n`nCo-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" }
$tmp = New-TemporaryFile
[IO.File]::WriteAllText($tmp, $textoCommit, (New-Object System.Text.UTF8Encoding $false))
Invoke-ComRetentativa { scp -q -o BatchMode=yes $tmp "${Servidor}:$Stage/.mensagem_commit" } "Falhou ao enviar a mensagem do commit"
Remove-Item $tmp

$arquivos = (Get-ArquivosSite) -join ' '
Write-Host "→ Backup, troca dos arquivos e registro no git..."
$script = @"
set -e
cd ~/domains/zagora.space/public_html
B=~/backups/`$(date +%Y%m%d-%H%M%S); mkdir -p `$B
for f in $arquivos; do [ -e "`$f" ] && cp -p "`$f" `$B/ || true; done
for f in $arquivos; do cp ~/$Stage/"`$f" "`$f.new"; case "`$f" in *.php) php -l "`$f.new" >/dev/null ;; esac; done
for f in $arquivos; do mv "`$f.new" "`$f"; done

# Landing page: backup do que está no ar e cópia da pasta site/ por cima (não apaga nada que já esteja lá)
for d in index.html pt privacidade assets robots.txt sitemap.xml; do [ -e "`$d" ] && cp -rp "`$d" `$B/ || true; done
# -rlt (sem -p): NÃO copia permissões — a pasta vem do Windows como 700 e isso já derrubou o site (27/09/2026)
rsync -rlt ~/$Stage/site/ ./
# Permissões sempre certas: pastas 755, arquivos 644, config.php só pro dono
chmod 755 .
find . -path ./.well-known -prune -o -type d -exec chmod 755 {} +
find . -maxdepth 3 -type f ! -name config.php -exec chmod 644 {} +
chmod 600 config.php
echo "backup em `$B"

if [ ! -d ~/zagora_repo/.git ]; then
  mkdir -p ~/zagora_repo && cd ~/zagora_repo && git init -q -b main
  git config user.name 'Nico' && git config user.email 'voce@exemplo.com'
fi
rsync -a --delete --exclude .git --exclude config.php --exclude .mensagem_commit ~/$Stage/ ~/zagora_repo/
cd ~/zagora_repo && git add -A
if git diff --cached --quiet; then echo 'git: nada mudou desde o último registro'; else git commit -q -F ~/$Stage/.mensagem_commit && git log --oneline -1; fi
rm -rf ~/$Stage
"@
# Com nova tentativa: se a conexão cair antes de começar, nada mudou; se cair no meio, repetir refaz tudo igual
$scriptLimpo = $script -replace "`r", ''
Invoke-ComRetentativa { ssh -o BatchMode=yes $Servidor $scriptLimpo | Out-Host } "Falhou a troca dos arquivos no servidor"

# Confere se continua tudo de pé
Write-Host "→ Conferindo o site..."
$ok = $true
function Get-Status($url, $metodo = 'GET', $contem = $null) {
    try {
        $r = Invoke-WebRequest $url -Method $metodo -Body $(if ($metodo -eq 'POST') { '{}' }) -ContentType 'application/json' -UseBasicParsing -TimeoutSec 20 -MaximumRedirection 0
        if ($contem -and -not $r.Content.Contains($contem)) { return "200 mas sem '$contem'" }
        $r.StatusCode
    }
    catch { $_.Exception.Response.StatusCode.value__ }
}
foreach ($c in @(
    @{ Nome = 'webhook recusa pedido falso'; Url = 'https://zagora.space/webhook.php'; Metodo = 'POST'; Esperado = 401 },
    @{ Nome = 'cron de lembretes responde'; Url = 'https://zagora.space/cron_lembretes.php'; Metodo = 'GET'; Esperado = 200 },
    @{ Nome = 'arquivos internos bloqueados'; Url = 'https://zagora.space/config.php'; Metodo = 'GET'; Esperado = 403 },
    @{ Nome = 'landing pt-BR no ar'; Url = 'https://zagora.space/'; Metodo = 'GET'; Esperado = 200; Contem = 'Controle financeiro' },
    @{ Nome = 'landing pt-PT no ar'; Url = 'https://zagora.space/pt/'; Metodo = 'GET'; Esperado = 200; Contem = 'Registe despesas' },
    @{ Nome = 'sitemap no ar'; Url = 'https://zagora.space/sitemap.xml'; Metodo = 'GET'; Esperado = 200 }
)) {
    $s = Get-Status $c.Url $c.Metodo $c.Contem
    $passou = $s -eq $c.Esperado
    $ok = $ok -and $passou
    Write-Host ("  {0} {1} ({2})" -f $(if ($passou) { '✅' } else { '❌' }), $c.Nome, $s)
}

if ($ok) { Write-Host "`n✅ No ar." -ForegroundColor Green } else { Write-Host "`n⚠️ Subiu, mas alguma conferência falhou — veja acima." -ForegroundColor Yellow; exit 1 }
