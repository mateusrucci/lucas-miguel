#!/usr/bin/env bash
#
# setup-vps.sh — Configura VPS Ubuntu para receber deploy do repositório lucas-miguel.
#
# O que faz:
#   1. Instala Nginx + PHP-FPM + rsync (idempotente)
#   2. Cria /var/www/lucas-miguel com dono www-data
#   3. Cria server block Nginx apontando para essa pasta com fastcgi para PHP
#   4. Gera par de chaves ed25519 dedicado para o deploy (~/.ssh/lucas_miguel_deploy)
#   5. Autoriza a chave pública em /root/.ssh/authorized_keys (só se ainda não estiver)
#   6. Imprime, no final, os valores exatos que devem ser cadastrados como GitHub Secrets
#
# Como usar (dentro do SSH do VPS, como root):
#   curl -fsSL https://raw.githubusercontent.com/mateusrucci/lucas-miguel/main/scripts/setup-vps.sh | bash
# ou baixando/copiando o arquivo e rodando: bash setup-vps.sh
#
set -euo pipefail

DEPLOY_PATH="/var/www/lucas-miguel"
SERVER_NAME="_"        # aceita qualquer host que bater no IP; troque por seu domínio quando tiver
NGINX_CONF="/etc/nginx/sites-available/lucas-miguel"
NGINX_LINK="/etc/nginx/sites-enabled/lucas-miguel"
DEPLOY_KEY="/root/.ssh/lucas_miguel_deploy"
PHP_SOCK_HINT=""

log() { printf "\n\033[1;36m[setup-vps]\033[0m %s\n" "$*"; }

log "1/6  Atualizando índice de pacotes e instalando Nginx, PHP-FPM, rsync..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y --no-install-recommends nginx php-fpm php-cli php-curl rsync openssh-client curl

# Descobre a versão do PHP-FPM instalada (ex.: 8.3)
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
PHP_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"
if [[ ! -S "$PHP_SOCK" ]]; then
  # fallback: pega o primeiro socket disponível
  PHP_SOCK=$(ls /run/php/php*-fpm.sock 2>/dev/null | head -n1 || true)
fi
if [[ -z "$PHP_SOCK" ]]; then
  echo "ERRO: não encontrei socket do php-fpm em /run/php/. Verifique se php-fpm está instalado." >&2
  exit 1
fi
log "PHP-FPM socket: $PHP_SOCK"

log "2/6  Criando diretório de deploy $DEPLOY_PATH..."
mkdir -p "$DEPLOY_PATH"
chown -R www-data:www-data "$DEPLOY_PATH"
chmod 755 "$DEPLOY_PATH"

# Página de teste caso ainda não tenha sido feito deploy
if [[ ! -f "$DEPLOY_PATH/index.html" ]] && [[ -z "$(ls -A "$DEPLOY_PATH" 2>/dev/null)" ]]; then
  cat > "$DEPLOY_PATH/index.html" <<'HTML'
<!doctype html>
<meta charset="utf-8">
<title>lucas-miguel • aguardando deploy</title>
<body style="font-family:system-ui;padding:2rem;color:#333">
<h1>VPS configurado ✔</h1>
<p>Nginx + PHP-FPM prontos. Rode o workflow "Deploy to VPS" no GitHub para enviar os arquivos.</p>
HTML
  chown www-data:www-data "$DEPLOY_PATH/index.html"
fi

log "3/6  Escrevendo server block do Nginx em $NGINX_CONF..."
cat > "$NGINX_CONF" <<EOF
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name ${SERVER_NAME};

    root ${DEPLOY_PATH};
    index index.html index.php;

    client_max_body_size 20M;

    # Bloqueia acesso a arquivos ocultos exceto .well-known
    location ~ /\.(?!well-known).* {
        deny all;
    }

    location / {
        try_files \$uri \$uri/ \$uri.html =404;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_read_timeout 60;
    }

    # Cache leve para assets
    location ~* \.(?:jpg|jpeg|png|gif|ico|svg|webp|css|js|woff2?|ttf|pdf)\$ {
        expires 7d;
        add_header Cache-Control "public, max-age=604800";
        try_files \$uri =404;
    }

    access_log /var/log/nginx/lucas-miguel.access.log;
    error_log  /var/log/nginx/lucas-miguel.error.log;
}
EOF

# Remove default e habilita nosso site
rm -f /etc/nginx/sites-enabled/default
ln -sf "$NGINX_CONF" "$NGINX_LINK"

log "4/6  Testando e recarregando Nginx..."
nginx -t
systemctl enable --now nginx >/dev/null 2>&1 || true
systemctl reload nginx
systemctl enable --now "php${PHP_VERSION}-fpm" >/dev/null 2>&1 || systemctl enable --now php-fpm >/dev/null 2>&1 || true

log "5/6  Gerando chave SSH dedicada para o GitHub Actions..."
mkdir -p /root/.ssh
chmod 700 /root/.ssh
if [[ ! -f "$DEPLOY_KEY" ]]; then
  ssh-keygen -t ed25519 -N "" -C "github-actions@lucas-miguel" -f "$DEPLOY_KEY" >/dev/null
fi
touch /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys
PUB=$(cat "${DEPLOY_KEY}.pub")
if ! grep -qxF "$PUB" /root/.ssh/authorized_keys; then
  echo "$PUB" >> /root/.ssh/authorized_keys
fi

# Descobre porta SSH configurada
SSH_PORT=$(grep -E '^\s*Port\s+' /etc/ssh/sshd_config 2>/dev/null | awk '{print $2}' | head -n1)
SSH_PORT=${SSH_PORT:-22}

# Descobre IP público
PUBLIC_IP=$(curl -fsS4 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')

log "6/6  Setup finalizado."

cat <<BANNER

============================================================
✅  VPS PRONTO. Agora cadastre estes GitHub Secrets no repo:
    https://github.com/mateusrucci/lucas-miguel/settings/secrets/actions
============================================================

SSH_HOST       = ${PUBLIC_IP}
SSH_USER       = root
SSH_PORT       = ${SSH_PORT}
DEPLOY_PATH    = ${DEPLOY_PATH}

SSH_PRIVATE_KEY (copiar TUDO abaixo, inclusive as linhas BEGIN/END):
------------------------------------------------------------
$(cat "$DEPLOY_KEY")
------------------------------------------------------------

Chave pública já autorizada em /root/.ssh/authorized_keys:
$(cat "${DEPLOY_KEY}.pub")

Depois de cadastrar os secrets, rode o workflow em:
  https://github.com/mateusrucci/lucas-miguel/actions/workflows/deploy.yml
ou faça um push na branch main que altere algum arquivo em Sites/.

Teste rápido: http://${PUBLIC_IP}/
BANNER
