# Processo Seletivo - Dev I

Aplicação Laravel para gestão de operações financeiras, com:

- autenticação de usuários;
- importação de planilhas (`.xlsx`, `.xls`, `.csv`) em background;
- listagem, detalhe e atualização de status de operações;
- histórico de mudança de status;
- geração de relatório com cálculo de valor presente.

---

## 1) Requisitos

Antes de rodar o projeto, garanta que você tem:

- PHP 8.2+
- Composer
- Node.js 18+ e npm
- Banco de dados (SQLite ou MySQL)

> Observação: o projeto usa fila em banco (`QUEUE_CONNECTION=database`) e sessão/cache em banco. Por isso, o banco precisa estar disponível durante a execução.

---

## 2) Passo a passo (primeira execução)

### Passo 1 — entrar na pasta do projeto

```bash
cd processoSeletivo
```

### Passo 2 — instalar dependências PHP

```bash
composer install
```

### Passo 3 — instalar dependências front-end

```bash
npm install
```

### Passo 4 — criar arquivo de ambiente

No Linux/macOS:

```bash
cp .env.example .env
```

No Windows (PowerShell):

```powershell
Copy-Item .env.example .env
```

### Passo 5 — gerar chave da aplicação

```bash
php artisan key:generate
```

### Passo 6 — configurar banco no arquivo .env

Você pode usar **SQLite** (mais rápido para iniciar) ou **MySQL**.

#### Opção A: SQLite (recomendado para desenvolvimento rápido)

No `.env`:

```dotenv
DB_CONNECTION=sqlite
```

Crie o arquivo caso não exista:

```bash
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
```

#### Opção B: MySQL

No `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=processo_seletivo
DB_USERNAME=seu_usuario
DB_PASSWORD=sua_senha
```

> Se no seu ambiente o MySQL estiver em outra porta (ex.: 3307), ajuste `DB_PORT`.

### Passo 7 — rodar migrations

```bash
php artisan migrate
```

### Passo 8 — popular dados iniciais (opcional)

```bash
php artisan db:seed
```

Isso cria:

- conveniadas padrão;
- usuário de teste: `test@example.com` (senha padrão da factory: `password`).

### Passo 9 — gerar assets de produção (evita erro de manifest)

```bash
npm run build
```

---

## 3) Como rodar o projeto no dia a dia

Você pode usar modo unificado ou manual.

### Opção 1 — comando único (recomendado)

```bash
composer run dev
```

Esse comando sobe ao mesmo tempo:

- servidor Laravel (`php artisan serve`);
- worker da fila (`php artisan queue:listen`);
- logs (`php artisan pail`);
- Vite em modo desenvolvimento (`npm run dev`).

### Opção 2 — manual (3 terminais)

Terminal 1 (aplicação):

```bash
php artisan serve
```

Terminal 2 (fila):

```bash
php artisan queue:work --queue=default --timeout=0 --tries=1
```

Terminal 3 (front-end, opcional em dev):

```bash
npm run dev
```

Se não quiser `npm run dev`, rode `npm run build` após alterações de front.

---

## 4) Acesso e rotas principais

- Login: `GET /login`
- Dashboard: `GET /dashboard`
- Lista de operações: `GET /operacoes`
- Detalhe da operação: `GET /operacoes/{operacao}`
- Importação: `POST /operacoes/importar`
- Alteração de status: `PATCH /operacoes/{operacao}/status`
- Relatório em Excel: `GET /operacoes/relatorio`

---

## 5) Importação de planilha

A importação é assíncrona e depende da fila.

Fluxo:

1. usuário envia arquivo;
2. sistema registra as linhas para processamento;
3. jobs são executados em background;
4. status das linhas pode ser acompanhado na tela de operações.

Cabeçalhos aceitos (principais):

- `valor_requerido`
- `valor_desembolso`
- `total_juros`
- `taxa_juros` / `taxa_juros_%`
- `taxa_multa`
- `taxa_mora`
- `status_id`
- `data_criacao`
- `data_pagamento`
- `produto`
- `conveniada_id`
- `quantidade_parcelas`
- `data_primeiro_vencimento`
- `valor_parcela`
- `quantidade_parcelas_pagas`
- `cpf`
- `nome`
- `dt_nasc`
- `sexo`
- `email`

Também existem aliases para compatibilidade de layout.

---

## 6) Regras de status

Status possíveis:

- `DIGITANDO`
- `PRÉ-ANÁLISE`
- `EM ANÁLISE`
- `PARA ASSINATURA`
- `ASSINATURA CONCLUÍDA`
- `APROVADA`
- `PAGO AO CLIENTE`
- `CANCELADA`

Regras importantes:

- toda alteração válida gera histórico em `historico_status`;
- ao mudar para `PAGO AO CLIENTE`, `data_pagamento` é atualizada automaticamente;
- `PAGO AO CLIENTE` só é permitido se a operação:
	- estiver em `APROVADA`;
	- já tiver passado por `ASSINATURA CONCLUÍDA` no histórico.

---

## 7) Regra do Valor Presente no relatório

O valor presente é calculado por parcela e somado no relatório.

Legenda:

- VP = Valor Presente
- V = Valor da Parcela
- m = Multa
- j = Juros de Mora
- i = Taxa da Operação
- d = Dias (atraso/adiantamento)

Fórmulas:

- Atraso:
	- `VP = V + (V * m) + (V * (j/30) * d)`
- Adiantamento:
	- `VP = V - (V * (i/30) * d)`

---

## 8) Comandos úteis

Limpar cache/config/rotas/views:

```bash
php artisan optimize:clear
```

Ver jobs com falha:

```bash
php artisan queue:failed
```

Reprocessar jobs com falha:

```bash
php artisan queue:retry all
```

Executar testes:

```bash
php artisan test
```

---

## 9) Troubleshooting (erros comuns)

### Erro de banco (conexão recusada / access denied)

- valide `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` no `.env`;
- confirme que o serviço do banco está iniciado;
- rode `php artisan migrate:status` para validar conexão.

### Importação não anda (fica processando)

- confirme se há worker rodando (`queue:work` ou `composer run dev`);
- verifique `php artisan queue:failed`;
- verifique logs em `storage/logs/laravel.log`.

### Erro de Vite/manifest

- rode `npm run build`;
- depois rode `php artisan optimize:clear`.

---

## 10) Resumo rápido (checklist)

1. `composer install`
2. `npm install`
3. copiar `.env` e gerar chave
4. configurar banco
5. `php artisan migrate`
6. `php artisan db:seed` (opcional)
7. `npm run build`
8. `composer run dev` (ou subir manualmente app + fila + vite)
