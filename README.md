
# PaySuite Gateway para Magento 2

Módulo de pagamento que redirecciona o cliente para a página de pagamento da [PaySuite](https://paysuite.tech) e confirma a encomenda automaticamente por webhook.

## Como funciona

1. O cliente escolhe **PaySuite** no checkout e faz a encomenda.
2. A encomenda fica **Pending** e o cliente é redireccionado para a PaySuite.
3. Depois de pagar, a PaySuite envia um webhook assinado (HMAC-SHA256) e a loja captura o pagamento, cria a factura e passa a encomenda a **Processing**.
4. O cliente volta à loja.

O módulo não cancela a encomenda quando recebe `payment.failed`, porque a PaySuite pode confirmar o mesmo pagamento depois. Se chegar um `payment.success` para uma encomenda cancelada, ela é reaberta e capturada.

## Requisitos

- Magento 2.4.x (série `103` do framework)
- PHP 8.1 ou superior
- Loja com **HTTPS** público (a PaySuite tem de conseguir chamar `/paysuite/webhook`)
- Conta PaySuite com **Token** da API e **Webhook Token** (`whsec_...`)

## Instalação com Composer (recomendado)

Na raiz do Magento:

```bash
composer config repositories.paysuite vcs https://github.com/TEU_USER/magento-paysuite.git
composer require paysuite/module-gateway:^1.0
bin/magento module:enable PaySuite_Gateway
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

Se o repositório for privado, o Composer pede um token de acesso do GitHub/GitLab.

## Instalação num servidor online (cPanel)

Pré-requisitos: acesso ao **Terminal** do cPanel (ou SSH) e PHP 8.1+ seleccionado em *MultiPHP Manager* para o domínio da loja.

### Opção A: Composer pelo terminal do cPanel

1. Abre **Terminal** no cPanel e entra na pasta do Magento (normalmente `public_html`):
   ```bash
   cd ~/public_html
   ```
2. Corre os comandos da secção anterior. Se o comando `composer` não existir, usa `php composer.phar` (descarregado de https://getcomposer.org/download/) ou `php -d memory_limit=-1 composer.phar require ...`.

### Opção B: Upload do ZIP pelo File Manager

1. No **File Manager**, cria a pasta `app/code/PaySuite/Gateway` dentro da raiz do Magento.
2. Faz upload do ficheiro `paysuite-gateway-x.y.z.zip` para essa pasta e usa **Extract**. Os ficheiros `registration.php`, `etc/`, `Model/`, etc. têm de ficar directamente em `Gateway/`, sem uma pasta extra pelo meio.
3. No **Terminal** (na raiz do Magento):
   ```bash
   php bin/magento module:enable PaySuite_Gateway
   php bin/magento setup:upgrade
   php bin/magento setup:di:compile
   php bin/magento setup:static-content:deploy -f
   php bin/magento cache:flush
   ```

### Notas para hospedagem partilhada

- O `setup:di:compile` precisa de muita memória. Se falhar, corre com `php -d memory_limit=-1 bin/magento setup:di:compile`.
- Corre sempre os comandos com o **mesmo utilizador do cPanel** que é dono dos ficheiros. Não uses `root`, senão `generated/` e `var/` ficam com permissões erradas e o site mostra "An error has happened during application run".
- Em modo `production`, depois de qualquer alteração ao módulo, repete `setup:di:compile` e `setup:static-content:deploy -f`.
- Para evitar que clientes vejam erros durante a instalação, usa `php bin/magento maintenance:enable` antes e `maintenance:disable` no fim.

## Configuração

No admin: **Stores → Configuration → Sales → Payment Methods → PaySuite**.

| Campo | Descrição |
|---|---|
| Activo | Liga o método no checkout |
| Token | Token da API (painel PaySuite) |
| Webhook Token | Segredo do webhook (`whsec_...`) do painel PaySuite |
| Debug (log) | Regista eventos em `var/log/system.log` |
| URL público (dev/túnel) | **Só para desenvolvimento** (ex.: ngrok). Em produção deixa vazio |

O URL do webhook é enviado automaticamente em cada pagamento (`https://a-tua-loja.com/paysuite/webhook`). Não precisas de o configurar no painel da PaySuite.

Depois de gravar, limpa a cache: `bin/magento cache:flush`.

## Testar o webhook sem a PaySuite

```bash
SECRET='o-teu-webhook-token'
BODY='{"event":"payment.success","data":{"id":"teste-1","reference":"000000001","amount":"10.00"}}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $NF}')
curl -i -X POST https://a-tua-loja.com/paysuite/webhook \
  -H "Content-Type: application/json" -H "X-Signature: $SIG" --data-binary "$BODY"
```

Respostas: `200` processado, `401` assinatura inválida, `404` encomenda não encontrada, `500` segredo não configurado.

## Resolução de problemas

| Sintoma | Causa provável |
|---|---|
| PaySuite não aparece no checkout | Módulo não activo, `setup:upgrade` por correr, ou estáticos por gerar |
| "An error has happened during application run" | Permissões em `generated/` e `var/`. Ver `var/log/exception.log` |
| Encomenda fica `Pending` depois de pagar | Webhook não chegou: URL não é HTTPS público, firewall/WAF a bloquear, ou Webhook Token errado |
| Webhook responde `500 Webhook secret not configured` | Falta o Webhook Token no admin |

## Limitações conhecidas

- Os reembolsos só deixam uma nota na encomenda. O Credit Memo cria-se manualmente.
- Numa encomenda reaberta depois de cancelada, o stock não é reservado de novo.
- O módulo não consulta a PaySuite para confirmar o estado do pagamento: confia no webhook assinado.

## Licença

PaySuite Gateway for Magento 2

Copyright (c) 2026 Hypertech, LDA. All rights reserved.

This software is proprietary. Unauthorized copying, modification,
distribution, sublicensing or resale of this software, in whole or in
part, via any medium, is strictly prohibited without the prior written
permission of Hypertech, LDA.

Permission is granted to merchants with an active PaySuite account to
install and use this software on their own Magento stores solely to
process payments through the PaySuite service.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
IN NO EVENT SHALL HYPERTECH, LDA BE LIABLE FOR ANY CLAIM, DAMAGES OR
OTHER LIABILITY ARISING FROM THE USE OF THIS SOFTWARE.

Contact: https://paysuite.tech