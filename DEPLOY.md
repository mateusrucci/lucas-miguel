# Deploy — lucas-miguel

Este repositório usa **GitHub Actions** para fazer deploy automático da pasta
`Sites/` para um VPS Hostinger (Ubuntu 24.04) via `rsync` sobre SSH.

Todo push na branch `main` que altera qualquer arquivo dentro de `Sites/`
dispara o workflow [`deploy.yml`](.github/workflows/deploy.yml) e sobe as
mudanças para o servidor.

---

## Sumário

- [Como funciona](#como-funciona)
- [Setup inicial (1x)](#setup-inicial-1x)
  - [1. Configurar o VPS](#1-configurar-o-vps)
  - [2. Cadastrar os secrets no GitHub](#2-cadastrar-os-secrets-no-github)
  - [3. Primeiro deploy](#3-primeiro-deploy)
- [Fluxo do dia a dia](#fluxo-do-dia-a-dia)
- [Rodar deploy manualmente](#rodar-deploy-manualmente)
- [O que o workflow faz](#o-que-o-workflow-faz)
- [Rollback](#rollback)
- [Troubleshooting](#troubleshooting)
- [Ajustes futuros](#ajustes-futuros)

---

## Como funciona

```
┌────────────────┐     git push       ┌──────────────────────┐
│ dev local      │ ─────────────────► │ GitHub main branch   │
└────────────────┘                    └──────────┬───────────┘
                                                 │ dispara workflow
                                                 ▼
                                     ┌──────────────────────┐
                                     │ GitHub Actions       │
                                     │ (ubuntu-latest)      │
                                     └──────────┬───────────┘
                                                │ rsync via SSH
                                                ▼
                                     ┌──────────────────────┐
                                     │ VPS Hostinger        │
                                     │ /var/www/lucas-miguel│
                                     └──────────────────────┘
```

- **Runner:** GitHub Actions (`ubuntu-latest`).
- **Autenticação:** chave SSH dedicada `ed25519`, guardada como secret
  `SSH_PRIVATE_KEY`. A chave pública é gerada no próprio VPS e adicionada
  em `/root/.ssh/authorized_keys` durante o [setup do VPS](#1-configurar-o-vps).
- **Transferência:** `rsync -avz --delete` (arquivos removidos localmente
  também somem do VPS).
- **Pós-deploy:** o workflow ajusta ownership (`www-data:www-data`),
  permissões (`755` dirs, `644` files) e recarrega o Nginx.

---

## Setup inicial (1x)

### 1. Configurar o VPS

Conecte no VPS via SSH e rode o script `scripts/setup-vps.sh`. Ele é
idempotente — pode rodar mais de uma vez sem quebrar nada.

```bash
ssh root@<IP-DO-VPS>
# senha atual do root

# opção A: pegar o script direto do repo
curl -fsSL https://raw.githubusercontent.com/mateusrucci/lucas-miguel/main/scripts/setup-vps.sh | bash

# opção B: colar o conteúdo do script manualmente
nano /root/setup-vps.sh    # cole o conteúdo
bash /root/setup-vps.sh
```

O script vai:

1. Instalar Nginx, PHP-FPM, `rsync`.
2. Criar `/var/www/lucas-miguel` (`www-data:www-data`, `755`).
3. Escrever um server block Nginx `/etc/nginx/sites-available/lucas-miguel`
   com suporte a PHP (para `submit.php` e `pageview.php`).
4. Gerar par SSH `~/.ssh/lucas_miguel_deploy` (privada + pública).
5. Adicionar a pública em `/root/.ssh/authorized_keys`.
6. Imprimir no final os **5 valores** que você precisa cadastrar como
   secrets no GitHub (`SSH_HOST`, `SSH_USER`, `SSH_PORT`, `DEPLOY_PATH`,
   `SSH_PRIVATE_KEY`).

> **Guarde a saída do script.** É a única vez que a chave privada
> aparece em texto — depois disso ela vive só no `/root/.ssh/` do VPS
> (permissão 600) e no secret do GitHub.

### 2. Cadastrar os secrets no GitHub

Vá em https://github.com/mateusrucci/lucas-miguel/settings/secrets/actions
e clique em **"New repository secret"** cinco vezes, criando exatamente
estes nomes (case-sensitive):

| Secret            | Valor                                                                                        |
|-------------------|----------------------------------------------------------------------------------------------|
| `SSH_HOST`        | IP público do VPS (o script imprime)                                                          |
| `SSH_USER`        | `root`                                                                                        |
| `SSH_PORT`        | `22` (ou a porta que o script detectou)                                                       |
| `DEPLOY_PATH`     | `/var/www/lucas-miguel`                                                                       |
| `SSH_PRIVATE_KEY` | Conteúdo **completo** do arquivo `~/.ssh/lucas_miguel_deploy` no VPS, incluindo BEGIN/END     |

**Alternativa via `gh` CLI (mais rápido):**

```bash
# depois de rodar o setup-vps.sh, guardando a saída em setup-output.txt

gh secret set SSH_HOST      -R mateusrucci/lucas-miguel -b "<IP-DO-VPS>"
gh secret set SSH_USER      -R mateusrucci/lucas-miguel -b "root"
gh secret set SSH_PORT      -R mateusrucci/lucas-miguel -b "22"
gh secret set DEPLOY_PATH   -R mateusrucci/lucas-miguel -b "/var/www/lucas-miguel"

# a chave privada: cole diretamente do VPS via ssh + cat
ssh root@<IP-DO-VPS> "cat /root/.ssh/lucas_miguel_deploy" \
  | gh secret set SSH_PRIVATE_KEY -R mateusrucci/lucas-miguel
```

### 3. Primeiro deploy

Com os 5 secrets cadastrados, dispare o workflow:

```bash
gh workflow run "Deploy to VPS" -R mateusrucci/lucas-miguel
gh run watch -R mateusrucci/lucas-miguel
```

Ou pela UI: **Actions → Deploy to VPS → Run workflow → Run**.

Se tudo estiver ok, `http://<IP-DO-VPS>/` responde com o `index.html`
ou com a página placeholder caso `Sites/` ainda não tenha um `index.html`
na raiz.

---

## Fluxo do dia a dia

```bash
# edite o que precisar em Sites/
git add Sites/...
git commit -m "ajusta hero da LP indaiatuba"
git push
```

Assim que o push chega no `main`, o workflow roda sozinho e leva ~30–60s
para publicar. Acompanhe em:
https://github.com/mateusrucci/lucas-miguel/actions

**Não dispara deploy quando:**

- você edita coisas fora de `Sites/` (ex.: `DEPLOY.md`, `README.md`).
- você faz push em outra branch que não seja `main`.

**Dispara deploy quando:**

- qualquer arquivo em `Sites/` muda.
- o próprio arquivo `.github/workflows/deploy.yml` muda.
- você aciona manualmente via `workflow_dispatch`.

---

## Rodar deploy manualmente

Útil pra:

- Forçar um deploy sem mudar arquivo (ex.: reinstalação depois de mexer no VPS).
- Depurar credenciais.

```bash
gh workflow run "Deploy to VPS" -R mateusrucci/lucas-miguel
```

Ou UI → **Actions → Deploy to VPS → Run workflow**.

---

## O que o workflow faz

Ver [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml). Resumo:

1. `actions/checkout@v4` — clona o repo no runner.
2. **Setup SSH key** — grava `SSH_PRIVATE_KEY` em `~/.ssh/deploy_key` (600)
   e alimenta `known_hosts` com `ssh-keyscan`.
3. **Rsync Sites/ → VPS** — `rsync -avz --delete` só do conteúdo de
   `Sites/` para `DEPLOY_PATH` no VPS. `.DS_Store` e `.swp` são excluídos.
4. **Fix permissions & reload nginx** — `chown www-data`, permissões,
   `nginx -t` e `systemctl reload nginx`.
5. **Cleanup** — apaga a chave privada do runner (`if: always()`).

`concurrency: deploy-vps` impede dois deploys correndo em paralelo (evita
race no rsync).

---

## Rollback

Como o repo tem histórico Git completo, dá pra voltar para qualquer
commit anterior:

```bash
# ver histórico
git log --oneline Sites/

# voltar para um commit específico (cria um novo commit revertendo)
git revert <sha>
git push
```

O workflow vai rodar de novo com o estado antigo e reverter o VPS.

Se precisar de **reversão emergencial** sem esperar o workflow:

```bash
ssh root@<IP-DO-VPS>
cd /var/www/lucas-miguel
# se você fizer snapshots manuais antes de cada deploy:
#   cp -a /var/www/lucas-miguel /var/www/lucas-miguel.bkp-YYYYMMDD
# volte com:
#   rsync -av --delete /var/www/lucas-miguel.bkp-YYYYMMDD/ /var/www/lucas-miguel/
```

*(Snapshots automáticos não estão configurados por padrão. Se quiser,
adicione um passo no workflow que faça `cp -a` antes do rsync.)*

---

## Troubleshooting

### `Permission denied (publickey)`

- A `SSH_PRIVATE_KEY` no secret está incompleta (esqueceu de copiar as
  linhas `-----BEGIN OPENSSH PRIVATE KEY-----` / `-----END ...-----`) ou
  tem quebras de linha erradas. Regere e cadastre de novo.
- A chave pública **não** foi adicionada ao `authorized_keys` do VPS.
  Rode o `scripts/setup-vps.sh` de novo — ele é idempotente.

### `Host key verification failed`

- O `ssh-keyscan` não conseguiu falar com o host. Confira se `SSH_HOST` e
  `SSH_PORT` estão certos e se o VPS aceita conexões de fora
  (`ufw status`, provedor de rede).

### `rsync: connection unexpectedly closed`

- Firewall bloqueando o IP do runner. GitHub-hosted runners saem de
  ranges publicados pela GitHub — se o VPS tem `ufw` restritivo ou
  fail2ban muito agressivo, considere usar um self-hosted runner ou
  liberar as portas SSH globalmente.

### `nginx -t` falha no pós-deploy

- Alguém editou o server block errado no VPS. Rode `nginx -t` no VPS
  para ver a linha que quebrou. Se você não mudou o Nginx, algum arquivo
  em `Sites/` pode ter nome com caractere inválido — `rsync` não deveria
  quebrar o Nginx, mas convém investigar.

### PHP não executa (arquivo baixa como texto)

- Socket do PHP-FPM está errado no server block. Rode:
  ```bash
  ls /run/php/
  # ajuste fastcgi_pass no /etc/nginx/sites-available/lucas-miguel
  # nginx -t && systemctl reload nginx
  ```

### Como validar deploy sem abrir o browser

```bash
curl -sI http://<IP-DO-VPS>/                # espera 200
curl -sI http://<IP-DO-VPS>/lp-vsl-ribeirao-preto.html  # espera 200
curl -s -X POST http://<IP-DO-VPS>/submit.php -d 'evento=x'  # espera JSON {ok:false, error:'evento'}
```

---

## Ajustes futuros

- **HTTPS:** rodar `apt install certbot python3-certbot-nginx` no VPS,
  depois `certbot --nginx -d seu-dominio.com`. O server block já vira
  HTTPS automaticamente e o Nginx reload continua funcionando.
- **Snapshot antes de rsync:** adicionar um passo no workflow entre
  "Setup SSH key" e "Rsync" que faz `cp -a` no VPS.
- **Domínio customizado:** editar `SERVER_NAME` em `/etc/nginx/sites-available/lucas-miguel`.
- **Slack/Discord notification:** adicionar step com
  `slackapi/slack-github-action@v1` disparado em `if: failure()`.

---

## Estrutura do repositório

```
lucas-miguel/
├── .github/
│   └── workflows/
│       └── deploy.yml           # este workflow
├── Sites/                       # <- tudo aqui é deployado
│   ├── assets/img/
│   ├── cidades/{cidade}/
│   ├── cidades-obg/{cidade}/
│   ├── cidades-quiz/{cidade}/
│   ├── cidades-quiz-quizzes/
│   ├── lp-vsl-ribeirao-preto.html
│   ├── obg-indaiatuba.html
│   ├── quiz-indaiatuba.html
│   ├── ebook-a-arte-de-delegar.html
│   ├── pageview.php
│   └── submit.php
├── scripts/
│   └── setup-vps.sh             # roda 1x no VPS
├── .gitignore
└── DEPLOY.md                    # este arquivo
```
