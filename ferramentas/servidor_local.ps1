# Servidor local só pra pré-visualizar a landing (pasta site/) no navegador: http://localhost:8321/
# Uso: .\ferramentas\servidor_local.ps1   (Ctrl+C pra parar)
param([int]$Porta = 8321)

$raiz = Join-Path (Split-Path $PSScriptRoot -Parent) 'site'
$tipos = @{ '.html' = 'text/html; charset=utf-8'; '.css' = 'text/css; charset=utf-8'; '.js' = 'application/javascript; charset=utf-8';
            '.svg' = 'image/svg+xml'; '.png' = 'image/png'; '.xml' = 'application/xml; charset=utf-8'; '.txt' = 'text/plain; charset=utf-8' }

$http = New-Object System.Net.HttpListener
$http.Prefixes.Add("http://localhost:$Porta/")
$http.Start()
Write-Host "Pré-visualização em http://localhost:$Porta/ (Ctrl+C pra parar)"
try {
    while ($http.IsListening) {
        $ctx = $http.GetContext()
        $caminho = [Uri]::UnescapeDataString($ctx.Request.Url.AbsolutePath).TrimStart('/')
        $arquivo = Join-Path $raiz $caminho
        if (Test-Path $arquivo -PathType Container) { $arquivo = Join-Path $arquivo 'index.html' }
        if ((Test-Path $arquivo -PathType Leaf) -and ([IO.Path]::GetFullPath($arquivo)).StartsWith($raiz)) {
            $bytes = [IO.File]::ReadAllBytes($arquivo)
            $ctx.Response.ContentType = $tipos[[IO.Path]::GetExtension($arquivo)]
            $ctx.Response.OutputStream.Write($bytes, 0, $bytes.Length)
        } else {
            $ctx.Response.StatusCode = 404
        }
        $ctx.Response.Close()
    }
} finally { $http.Stop() }
