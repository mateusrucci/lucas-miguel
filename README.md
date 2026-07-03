# lucas-miguel

Landing pages, quizzes e páginas de obrigado do funil do Lucas Miguel
(evento "Liberdade Operacional"), com tracking server-side de Meta CAPI
e integração com Apps Script + Make por cidade.

- **Deploy automático** por GitHub Actions no push em `main` → VPS Hostinger.
- Toda a lógica de deploy e setup do VPS está em [`DEPLOY.md`](DEPLOY.md).
- Script único de provisionamento do VPS em [`scripts/setup-vps.sh`](scripts/setup-vps.sh).

## Estrutura

```
Sites/                     # deployado para /var/www/lucas-miguel no VPS
├── assets/img/            # imagens compartilhadas
├── cidades/{cidade}/      # LP por cidade
├── cidades-obg/{cidade}/  # obrigado por cidade
├── cidades-quiz/          # quiz por cidade
├── lp-vsl-ribeirao-preto.html
├── quiz-indaiatuba.html
├── obg-indaiatuba.html
├── ebook-a-arte-de-delegar.html
├── pageview.php           # Meta CAPI PageView (server-side)
└── submit.php             # form → Apps Script + Make + Meta CAPI Lead
```
