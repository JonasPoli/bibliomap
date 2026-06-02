#!/usr/bin/env bash
# =============================================================================
#  build.sh — BiblioMap
#  Limpeza completa de cache e recompilação de assets.
#
#  Uso:
#    ./build.sh              → ambiente local  (php84)
#    ./build.sh --prod       → produção        (/RunCloud/Packages/php84rc/bin/php)
#    ./build.sh --prod --env=prod   → também seta APP_ENV=prod
# =============================================================================

set -euo pipefail

# ── Cores ────────────────────────────────────────────────────────────────────
BOLD='\033[1m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
DIM='\033[2m'
NC='\033[0m'

# ── Argumentos ───────────────────────────────────────────────────────────────
IS_PROD=false
APP_ENV_OVERRIDE=""

for arg in "$@"; do
    case "$arg" in
        --prod)        IS_PROD=true ;;
        --env=prod)    APP_ENV_OVERRIDE="prod" ;;
        --env=dev)     APP_ENV_OVERRIDE="dev" ;;
        --help|-h)
            echo ""
            echo "  Uso: ./build.sh [--prod] [--env=prod|dev]"
            echo ""
            echo "  (sem flags)   → local, usa 'php84'"
            echo "  --prod        → produção, usa /RunCloud/Packages/php84rc/bin/php"
            echo "  --env=prod    → força APP_ENV=prod"
            echo ""
            exit 0
            ;;
    esac
done

# ── Seleciona binário PHP ─────────────────────────────────────────────────────
if $IS_PROD; then
    PHP="/RunCloud/Packages/php84rc/bin/php"
else
    # Tenta php84; se não existir, cai para php
    if command -v php84 &>/dev/null; then
        PHP="php84"
    elif command -v php &>/dev/null; then
        PHP="php"
        echo -e "${YELLOW}⚠  'php84' não encontrado — usando '$(command -v php)'${NC}"
    else
        echo -e "${RED}✗  Nenhum binário PHP encontrado. Instale php84 ou passe --prod.${NC}"
        exit 1
    fi
fi

# Valida binário
if ! "$PHP" --version &>/dev/null; then
    echo -e "${RED}✗  Binário PHP não funcional: ${PHP}${NC}"
    exit 1
fi

PHP_VERSION=$("$PHP" -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
BIN="$PHP bin/console"

# ── Ambiente ──────────────────────────────────────────────────────────────────
if [ -n "$APP_ENV_OVERRIDE" ]; then
    export APP_ENV="$APP_ENV_OVERRIDE"
fi
CURRENT_ENV="${APP_ENV:-dev}"

# ── Header ────────────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${BLUE}╔══════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${BLUE}║          BiblioMap — Build & Cache Clear         ║${NC}"
echo -e "${BOLD}${BLUE}╚══════════════════════════════════════════════════╝${NC}"
echo -e "  ${DIM}PHP      : ${PHP} (${PHP_VERSION})${NC}"
echo -e "  ${DIM}Ambiente : ${CURRENT_ENV}${NC}"
echo -e "  ${DIM}Modo     : $( $IS_PROD && echo 'Produção' || echo 'Local' )${NC}"
echo ""

step() { echo -e "${CYAN}▸ $1${NC}"; }
ok()   { echo -e "${GREEN}  ✓ $1${NC}"; }
skip() { echo -e "${DIM}  ─ $1 (pulado)${NC}"; }
warn() { echo -e "${YELLOW}  ⚠ $1${NC}"; }

# ── 1. Cache do Symfony ───────────────────────────────────────────────────────
step "Limpando cache do Symfony..."
$BIN cache:clear --no-warmup 2>/dev/null || true
rm -rf var/cache/*
ok "var/cache limpo"

# ── 2. Cache do Doctrine (metadata / query / result) ─────────────────────────
step "Limpando caches do Doctrine..."
$BIN doctrine:cache:clear-metadata --flush 2>/dev/null && ok "metadata" || skip "metadata"
$BIN doctrine:cache:clear-query    --flush 2>/dev/null && ok "query"    || skip "query"
$BIN doctrine:cache:clear-result   --flush 2>/dev/null && ok "result"   || skip "result"

# ── 3. Cache de sessões ───────────────────────────────────────────────────────
if [ -d "var/sessions" ] && [ -n "$(ls -A var/sessions 2>/dev/null)" ]; then
    step "Limpando sessões expiradas..."
    find var/sessions -type f -name "*.sess" -mtime +1 -delete 2>/dev/null || true
    ok "Sessões antigas removidas"
else
    skip "Nenhuma sessão para limpar"
fi

# ── 4. Logs ───────────────────────────────────────────────────────────────────
step "Limpando logs..."
if [ -d "var/log" ]; then
    # Mantém arquivos dos últimos 7 dias em prod, 1 dia em dev
    DAYS=$( $IS_PROD && echo 7 || echo 1 )
    find var/log -type f \( -name "*.log" -o -name "*.log.*" \) -mtime +${DAYS} -delete 2>/dev/null || true
    ok "Logs com mais de ${DAYS} dia(s) removidos"
fi

# ── 5. Uploads/temp ───────────────────────────────────────────────────────────
step "Limpando arquivos temporários de upload..."
if [ -d "var/tmp" ]; then
    find var/tmp -type f -mtime +1 -delete 2>/dev/null || true
    ok "var/tmp limpo"
else
    skip "var/tmp não existe"
fi

# Limpa uploads de dataset processados (mantém uploads/seo que são imagens OG)
if [ -d "public/uploads/datasets" ]; then
    step "Limpando uploads de datasets já importados..."
    # Só remove arquivos csv/txt mais antigos que 30 dias
    find public/uploads/datasets -type f \( -name "*.csv" -o -name "*.txt" -o -name "*.xls" -o -name "*.xlsx" \) -mtime +30 -delete 2>/dev/null || true
    ok "Datasets antigos (>30 dias) removidos"
else
    skip "Nenhum diretório de uploads de dataset"
fi

# ── 6. Arquivos compilados de assets (AssetMapper) ────────────────────────────
step "Removendo assets compilados antigos..."
if [ -d "public/assets" ]; then
    rm -rf public/assets/*
    ok "public/assets limpo"
else
    skip "public/assets não existe"
fi

# ── 7. Recompilar Assets (AssetMapper / importmap) ────────────────────────────
step "Compilando assets (AssetMapper)..."
$BIN importmap:install 2>/dev/null || warn "importmap:install ignorado (sem alterações)"
$BIN asset-map:compile
ok "Assets compilados em public/assets"

# ── 8. Warm-up do cache ───────────────────────────────────────────────────────
step "Aquecendo cache (${CURRENT_ENV})..."
$BIN cache:warmup
ok "Cache aquecido"

# ── 9. Verificar rotas (sanity check) ────────────────────────────────────────
step "Verificando rotas..."
ROUTE_COUNT=$($BIN debug:router --no-ansi 2>/dev/null | grep -c "app_" || echo "0")
ok "${ROUTE_COUNT} rota(s) da aplicação encontradas"

# ── 10. Em produção: validações extras ───────────────────────────────────────
if $IS_PROD; then
    echo ""
    step "Validações de produção..."

    # Verifica se .env.local existe
    if [ -f ".env.local" ]; then
        ok ".env.local presente"
    else
        warn ".env.local não encontrado — certifique-se de que DATABASE_URL está configurada"
    fi

    # Verifica se o diretório de uploads SEO existe
    mkdir -p public/uploads/seo
    ok "Diretório public/uploads/seo garantido"

    # Permissões de var/
    chmod -R 775 var/ 2>/dev/null && ok "Permissões de var/ ajustadas" || skip "Ajuste de permissões (sem sudo)"

    # Permissões de public/uploads/
    if [ -d "public/uploads" ]; then
        chmod -R 775 public/uploads/ 2>/dev/null && ok "Permissões de uploads/ ajustadas" || true
    fi
fi

# ── 11. Migrations pendentes ──────────────────────────────────────────────────
step "Verificando migrations pendentes..."
PENDING=$($BIN doctrine:migrations:status --no-ansi 2>/dev/null | grep -c "New Migrations" || echo "0")
if echo "$PENDING" | grep -q "^0$"; then
    ok "Nenhuma migration pendente"
else
    warn "Há migrations pendentes — execute: $PHP bin/console doctrine:migrations:migrate"
fi

# ── Rodapé ────────────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${GREEN}╔══════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${GREEN}║           ✓  Build concluído com sucesso!        ║${NC}"
echo -e "${BOLD}${GREEN}╚══════════════════════════════════════════════════╝${NC}"
echo ""
