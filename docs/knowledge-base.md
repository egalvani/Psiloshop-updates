# Base de Conhecimento PixGo Payments WC

## Contexto

Este workspace consolida o checkout PixGo criado na conversa anterior. A versao mais recente e `pixgo-payments-wc`, empacotada como `pixgo-payments-wc-1.1.7.zip`.

O objetivo do plugin e adicionar um gateway PIX PixGo ao WooCommerce para a Psiloshop, com geracao de QR Code, codigo PIX copia e cola, tela de obrigado customizavel e confirmacao automatica por webhook.

## Produto atual

- Nome do plugin: `PixGo Payments WC`
- Slug/text domain: `pixgo-payments-wc`
- Gateway WooCommerce: `pixgo_payments_wc`
- Versao: `1.1.7`
- Requisitos: WordPress 6.0+, PHP 7.4+, WooCommerce ativo
- API base: `https://pixgo.org/api/v1`

## Fluxo de pagamento

1. Cliente escolhe `PixGo PIX` no checkout.
2. O plugin valida se existe API Key configurada.
3. O gateway fica indisponivel para pedidos abaixo de R$ 10,00.
4. Se houver limite maximo configurado, pedidos acima desse limite tambem sao bloqueados.
5. Ao processar o pedido, o plugin chama `POST /payment/create`.
6. O payload solicita expiracao em 30 minutos via `expires_in: 1800`.
7. A resposta salva metadados do pagamento PixGo no pedido.
8. O pedido muda para o status configurado em `Status ao gerar PIX`, por padrao `on-hold`.
9. A tela de obrigado mostra QR Code, PIX copia e cola, modal de alerta bancario e polling de status.
10. O webhook assinado confirma, expira ou reembolsa o pedido.

## Configuracoes do gateway

- Ativar gateway.
- Titulo exibido no checkout.
- Descricao exibida no checkout.
- API Key PixGo.
- Webhook Secret para validacao HMAC.
- URL publica do webhook.
- Status quando pago.
- Status quando expirar.
- Status quando reembolsar.
- Valor maximo por pedido.
- Confirmacao reforcada via consulta extra de API.
- Logs tecnicos no WooCommerce.
- Status inicial quando o PIX e gerado.

## Webhook

Endpoint publico:

```text
/?wc-api=pixgo_payments_wc_webhook
```

Headers esperados:

- `X-Webhook-Timestamp`
- `X-Webhook-Signature`
- `X-Webhook-Event`

A assinatura usa HMAC-SHA256 sobre:

```text
timestamp.payload
```

O plugin aceita uma janela de 900 segundos para reduzir replay.

Eventos tratados:

- `payment.completed`: confirma pagamento, chama `payment_complete`, limpa checagens agendadas e aplica o status pago configurado.
- `payment.expired`: aplica o status de expirado configurado.
- `payment.refunded`: aplica o status de reembolso configurado.

## Tela de obrigado e Elementor

O plugin pode usar layout nativo ou uma pagina editavel.

Menu:

```text
PSILOSHOP > PixGo Payments WC > Telas
```

Shortcodes disponiveis:

- `[pixgo_qr_code]`
- `[pixgo_pix_code]`
- `[pixgo_copy_button]`
- `[pixgo_payment_status]`
- `[pixgo_approved_message]`
- `[pixgo_order_number]`
- `[pixgo_order_total]`

A pagina padrao criada pelo plugin se chama `Pagamento PixGo - Psiloshop` e nasce privada.

## Frontend

O arquivo `assets/js/frontend.js` cuida de:

- polling AJAX do pedido;
- exibicao da mensagem de pagamento aprovado;
- botao de copiar codigo PIX;
- fallback quando Clipboard API nao esta disponivel.

O arquivo `assets/css/frontend.css` estiliza a area de pagamento PIX na pagina de obrigado e no template editavel.

## Alerta PIX

Menu:

```text
PSILOSHOP > PixGo Payments WC > Alerta PIX
```

Permite editar o titulo, a mensagem e o botao do modal exibido quando o cliente chega na tela do QR Code. O objetivo e tranquilizar o cliente caso algum banco digital mostre alerta preventivo de fraude por falso positivo.

## Admin

O arquivo `assets/css/admin.css` estiliza a pagina de status/configuracao em `PSILOSHOP > PixGo Payments WC`.

## Decisoes preservadas

- A versao ativa e `pixgo-payments-wc` 1.1.7.
- As iteracoes antigas foram preservadas em `archive/iterations/`, nao descartadas.
- O ZIP instalavel principal foi separado em `packages/`.
- O preview HTML foi mantido em `previews/` para revisao visual rapida.

## Pontos de atencao

- Confirmar com a PixGo se os nomes reais dos headers de webhook estao exatamente como implementados.
- Confirmar se o payload real de `POST /payment/create` e a resposta da API seguem o formato esperado pelo plugin.
- Testar em uma loja WooCommerce real ou ambiente staging antes de ativar em producao.
- Validar se a Psiloshop quer manter o limite minimo de R$ 10,00 embutido no gateway.
- Revisar textos finais para acentuacao e tom de marca antes da entrega publica.
