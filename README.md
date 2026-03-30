# Processo Seletivo - Dev I

Aplicação Laravel para gestão de operações financeiras, com:

- autenticação de usuários;
- importação de planilhas (`.xlsx`, `.xls`, `.csv`) em background;
- listagem, detalhe e atualização de status de operações;
- histórico de mudança de status;
- geração de relatório com cálculo de valor presente;
- filtros avançados por código, cliente, produto, conveniada e status.

---

## ⚡ QUICK START (Máquina Nova)

Se você está começando do zero, siga este fluxo:

### 1) Entrar na pasta

```bash
cd processoSeletivo
```

### 2) Instalar dependências

```bash
composer install
npm install
```

### 3) Criar `.env` e gerar chave

Windows (PowerShell):

```powershell
Copy-Item .env.example .env
php artisan key:generate
```

Linux/macOS:

```bash
cp .env.example .env
php artisan key:generate
```

### 4) Configurar banco no `.env`

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=processo_seletivo
DB_USERNAME=root
DB_PASSWORD=
```

> ⚠️ **Importante:** Verifique qual porta seu MySQL está usando:
> - **Instalação padrão:** porta `3306`
> - **XAMPP:** geralmente porta `3307`
>
> Se criou o banco em porta diferente, ajuste `DB_PORT` acima e execute:
> ```bash
> mysql -u root -P [PORTA_USADA] -e "CREATE DATABASE processo_seletivo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
> ```
> Substitua `[PORTA_USADA]` pela porta correta (ex: `3307` para XAMPP).


# 7-8. Crie banco e dados
php artisan migrate
php artisan db:seed
```

### 6) Gerar assets

```bash
npm run build
```

### 7) Subir aplicação

```bash
composer run dev
```

Acesse: `http://127.0.0.1:8000`  
Não há usuário padrão. Faça seu cadastro em `http://127.0.0.1:8000/register`.

✅ **Pronto: no final, agora é só importar o arquivo na tela de operações.**

---

## 1) Requisitos

Antes de rodar o projeto, garanta que você tem:

- **PHP 8.2+** com extensões: `openssl`, `pdo`, `pdo_mysql` ou `pdo_sqlite`
- **Composer** (PHP dependency manager)
- **Node.js 18+** e **npm**
- **Banco de dados:** MySQL 5.7+ (recomendado) ou SQLite

> **Observação importante:** o projeto usa fila em banco (`QUEUE_CONNECTION=database`) e sessão/cache em banco. Por isso, o banco precisa estar disponível e acessível durante toda a execução.

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

### Passo 6 — configurar banco no arquivo `.env`

#### 🟢 Opção A: MySQL (recomendado)

**Edite o arquivo `.env`:**

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=processo_seletivo
DB_USERNAME=root
DB_PASSWORD=
```

**Importante:** Se seu MySQL está em outra porta (ex.: XAMPP usa 3307 por padrão), ajuste `DB_PORT`:

```dotenv
DB_PORT=3307
```

**Crie o banco de dados** (se não existir):

```bash
mysql -u root -P 3306 -e "CREATE DATABASE processo_seletivo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

> Se usar porta 3307: `mysql -u root -P 3307 -e "..."`

#### 🔵 Opção B: SQLite (mais rápido para testes)

**Edite o arquivo `.env`:**

```dotenv
DB_CONNECTION=sqlite
```

**Crie o arquivo de banco:**

```bash
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
```

### Passo 7 — rodar migrations

```bash
php artisan migrate
```

Se houver erro de conexão, valide:
- O banco está iniciado?
- As credenciais no `.env` estão corretas?
- A porta está acessível?

### Passo 8 — popular dados iniciais (recomendado)

```bash
php artisan db:seed
```

Isso cria:
- **conveniadas** padrão (Conveniada 1 a 10)
- **não cria usuário padrão**

Após subir o projeto, acesse `/register` e crie seu próprio usuário.

### Passo 9 — gerar assets de produção

```bash
npm run build
```

Isso evita erro de `manifest.json` não encontrado.

---

## 3) Como rodar o projeto no dia a dia

### ✅ Opção 1 — Comando único (RECOMENDADO)

```bash
composer run dev
```

Esse comando sobe automaticamente:

- Servidor Laravel na porta **8000** (`http://127.0.0.1:8000`)
- Worker da fila em background
- Logs em tempo real
- Vite (frontend build) na porta **5174**

**Aguarde ~5 segundos** para o servidor iniciar completamente.

### Opção 2 — Manual (3-4 terminais separados)

**Terminal 1 - Servidor Laravel:**
```bash
php artisan serve
```

**Terminal 2 - Worker da fila:**
```bash
php artisan queue:work --queue=default --timeout=0 --tries=1
```

**Terminal 3 - Logs em tempo real:**
```bash
php artisan pail
```

**Terminal 4 - Frontend (opcional, apenas se modificar CSS/JS):**
```bash
npm run dev
```

---

## 4) Acesso e rotas principais

| Descrição | URL |
|-----------|-----|
| Login | `GET /login` |
| Dashboard | `GET /dashboard` |
| Lista de operações | `GET /operacoes` |
| Detalhe da operação | `GET /operacoes/{id}` |
| Importar planilha | `POST /operacoes/importar` |
| Alterar status | `PATCH /operacoes/{id}/status` |
| Gerar relatório | `GET /operacoes/relatorio` |

**Autenticação:**
- Não há credenciais padrão.
- Cadastre um usuário em `GET /register`.

---

## 5) Importação de planilha

### Fluxo

1. Acesse: `GET /operacoes`
2. Escolha um arquivo (`.xlsx`, `.xls` ou `.csv`)
3. Clique em "Importar"
4. O sistema processa em background
5. Acompanhe o progresso na tela

### Regras de Importação

#### Colunas Obrigatórias

- `cpf` ou `cliente_cpf` ou variações (ex: `documento`)
- `valor_requerido` (valor solicitado)

#### Colunas Opcionais

- `nome_cliente` / `cliente_nome` — nome do cliente
- `data_nascimento` — data de nascimento do cliente
- `email` / `cliente_email` — email
- `valor_desembolso` — valor desembolsado
- `total_juros` — total de juros
- `taxa_juros` / `taxa_juros_%` — taxa em percentual
- `taxa_multa` — taxa de multa
- `taxa_mora` — taxa de mora
- `status` / `status_id` — status inicial
- `data_criacao` — data de criação
- `data_pagamento` — data de pagamento
- **`produto`** — tipo de produto (CONSIGNADO, NAO_CONSIGNADO, etc)
- **`conveniada_id`** ou `codigo_conveniada` — ID ou código da conveniada
- `quantidade_parcelas` — quantidade de parcelas
- `data_primeiro_vencimento` — vencimento da primeira parcela
- `valor_parcela` — valor de cada parcela
- `quantidade_parcelas_pagas` — parcelas já pagas

#### Regras de Produto e Conveniada

| Produto | Conveniada | Comportamento |
|---------|-----------|---|
| `CONSIGNADO` | Obrigatória | Falha se não encontrada |
| `NAO_CONSIGNADO` | Pode ser vazia | Importa normalmente, conveniada_id fica NULL |
| Outros | Obrigatória | Falha se não encontrada |

> **Nota:** O CPF é **sempre preservado** do arquivo original (sem normalização).

---

## 6) Filtros de Busca

Na listagem de operações, você pode filtrar por:

- **Código** — prefixo do código da operação
- **Cliente** — nome ou CPF do cliente (busca com LIKE)
- **Produto** — CONSIGNADO ou NAO_CONSIGNADO
- **Conveniada** — nome da conveniada
- **Status** — um dos 8 status possíveis

**Comportamento especial:** Se você selecionar `produto = NAO_CONSIGNADO`, o filtro de conveniada é **automaticamente ignorado**, retornando todos os registros com esse produto.

---

## 7) Regras de Status

| Status | Descrição |
|--------|-----------|
| `DIGITANDO` | Digitação/preenchimento em andamento |
| `PRÉ-ANÁLISE` | Aguardando análise |
| `EM ANÁLISE` | Sendo analisada |
| `PARA ASSINATURA` | Pronta para assinatura |
| `ASSINATURA CONCLUÍDA` | Assinada (pré-requisito para pagamento) |
| `APROVADA` | Aprovada |
| `PAGO AO CLIENTE` | Pagamento realizado |
| `CANCELADA` | Cancelada (final, não pode ser alterada) |

### Regras de Transição

- Toda alteração de status gera registro em `historico_status`
- `PAGO AO CLIENTE` só é permitido se:
  - Status atual for `APROVADA`
  - A operação já passou por `ASSINATURA CONCLUÍDA` antes
- Ao marcar como `PAGO AO CLIENTE`, `data_pagamento` é atualizada automaticamente
- `CANCELADA` não pode ser alterada (status final)

---

## 8) Valor Presente (Relatório)

O relatório calcula o **Valor Presente** de cada parcela baseado em:

- **Atraso:** `VP = V + (V × m) + (V × (j/30) × d)`
- **Adiantamento:** `VP = V - (V × (i/30) × d)`

Onde:
- `V` = Valor da parcela
- `m` = Taxa de multa
- `j` = Taxa de mora (juros)
- `i` = Taxa da operação
- `d` = Dias de atraso (negativo = adiantado)

---

## 9) Comandos Úteis

```bash
# Limpar todos os caches
php artisan optimize:clear

# Ver jobs que falharam
php artisan queue:failed

# Reprocessar jobs falhados
php artisan queue:retry all

# Executar testes
php artisan test

# Ver status das migrations
php artisan migrate:status

# Reverter última migration
php artisan migrate:rollback
```

---

## 10) Troubleshooting (Problemas Comuns)

### ❌ "SQLSTATE[HY000]: General error: 1030 Got error..."

**Causa:** Banco de dados corrompido ou problema de conexão.

**Solução:**
```bash
# No MySQL, reconecte:
php artisan migrate:refresh --seed
```

### ❌ "Module 'openssl' already loaded in php.ini"

**Causa:** OpenSSL carregado duas vezes no `php.ini`.

**Solução:**
- Abra `php.ini`
- Procure por `extension=openssl` e `extension=php_openssl.dll`
- Comente uma delas (adicione `;` no início)
- Reinicie o servidor

### ❌ "Call to undefined function pcntl_signal (php artisan pail)"

**Causa:** Extensão `pcntl` não disponível no Windows.

**Solução:**
- A aplicação já tem um workaround: `scripts/pail-or-sleep.php`
- Use `composer run dev` que funciona automaticamente

### ❌ "Importação não progride (fica em 'processing')"

**Verificação:**
1. Verifique se há worker rodando: `composer run dev` deve estar ativo
2. Cheque jobs pendentes: `php artisan queue:failed`
3. Verifique logs: `storage/logs/laravel.log`
4. Se houver muitos jobs, limpe e recomeçe:
   ```bash
   php artisan queue:flush
   php artisan db:seed  # Recria dados de teste
   ```

### ❌ "CORS error ao acessar a aplicação"

**Solução:**
- Acesse exatamente: `http://127.0.0.1:8000` (não `localhost`)
- Certifique-se de que `APP_URL=http://localhost` está no `.env`

### ❌ "npm run dev falha com 'EACCES' ou permission denied"

**Solução (Windows):**
```bash
npm install -g npm
npm cache clean --force
npm install
npm run dev
```

---

## 11) Resumo Rápido (Checklist)

- [ ] `composer install`
- [ ] `npm install`
- [ ] `Copy-Item .env.example .env` (Windows) ou `cp .env.example .env` (Linux/Mac)
- [ ] `php artisan key:generate`
- [ ] Editar `.env` com credenciais de banco
- [ ] `php artisan migrate`
- [ ] `php artisan db:seed`
- [ ] `npm run build`
- [ ] `composer run dev`
- [ ] Acessar `http://127.0.0.1:8000`
- [ ] Cadastrar usuário em `http://127.0.0.1:8000/register`
- [ ] Importar o arquivo na tela de operações (`/operacoes`)

---

## 12) Estrutura de Pastas Principais

```
app/
  ├── Http/Controllers/OperacaoController.php  (lógica de listagem e filtros)
  ├── Imports/OperacoesImport.php             (importação de planilhas)
  ├── Models/                                  (Operacao, Cliente, Conveniada, etc)
  └── Jobs/                                    (jobs assíncronos de importação)

resources/views/operacoes/
  ├── index.blade.php    (listagem com filtros)
  ├── show.blade.php     (detalhe e histórico)
  └── edit.blade.php     (alteração de status)

database/
  ├── migrations/        (schemas das tabelas)
  └── seeders/           (dados iniciais)
```
