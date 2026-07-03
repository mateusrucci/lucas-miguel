# Deploy — lucas-miguel

Este repositório segue o formato usado em **Modernitty / lp.soufit.com**:
VPS Hostinger com Docker + Traefik, e deploy automático por GitHub Actions
fazendo `rsync` via SSH para a VPS.

## Destino

```text
Dominio publico: https://lp.liberdadeoperacional.com.br
Path na VPS: /opt/lucas-miguel
HTML publicado: /opt/lucas-miguel/html
Stack: lucas-miguel-nginx + lucas-miguel-php
Proxy reverso: Traefik externo na rede Docker n8n_default
```

## Estrutura

```text
Sites/                 # conteúdo público
ops/docker-compose.yml # stack Docker
ops/nginx.conf         # virtual host interno do container nginx
ops/php.Dockerfile     # PHP-FPM com extensão curl
```

## Deploy automático

Todo push na `main` dispara `.github/workflows/deploy.yml`:

1. Configura a chave SSH a partir do secret `VPS_SSH_KEY`.
2. Cria `/opt/lucas-miguel/html` na VPS.
3. Sincroniza `Sites/` para `/opt/lucas-miguel/html/`.
4. Sincroniza `ops/` para `/opt/lucas-miguel/`.
5. Roda `docker compose up -d --build`.
6. Valida `PUBLIC_URL` ou `https://lp.liberdadeoperacional.com.br/cidades/indaiatuba/`.

## Secrets necessários

Configure no GitHub:

```text
VPS_SSH_KEY   chave privada SSH usada pelo GitHub Actions
VPS_HOST      IP/host da VPS
VPS_USER      root
```

Opcionais:

```text
VPS_PORT      porta SSH, default 22
VPS_PATH      path de deploy, default /opt/lucas-miguel
PUBLIC_URL    URL de validação, default https://lp.liberdadeoperacional.com.br/cidades/indaiatuba/
```

## Setup inicial na VPS

Na VPS, o Traefik deve existir e expor `80/443`, com a rede Docker externa
`n8n_default`. Esse é o mesmo padrão da Modernitty.

Checklist:

```bash
docker --version
docker compose version
docker network inspect n8n_default >/dev/null
docker ps --format 'table {{.Names}}\t{{.Ports}}' | grep -E 'traefik|:80|:443'
```

Depois de configurar os secrets, o primeiro deploy pode ser disparado:

```bash
gh workflow run "Deploy -> VPS Hostinger" -R mateusrucci/lucas-miguel
gh run watch -R mateusrucci/lucas-miguel
```

## Validação

```bash
curl -I "https://lp.liberdadeoperacional.com.br/cidades/indaiatuba/?v=$(date +%s)"
curl -X POST "https://lp.liberdadeoperacional.com.br/pageview.php" \
  -d "event_id=test-$(date +%s)&url=https://lp.liberdadeoperacional.com.br/cidades/indaiatuba/"
```

Esperado:

```text
HTTP/2 200
{"ok":true}
```

## Observações

- `Sites/submit.php` e `Sites/pageview.php` precisam de PHP com `curl`.
- O token CAPI continua server-side nos arquivos PHP.
- O DNS do domínio deve apontar para a VPS onde o Traefik está ativo.
- Se Cloudflare estiver com proxy ligado, o Traefik ainda deve conseguir
  emitir/servir TLS conforme a configuração da VPS.
