#!/bin/bash
# ═══════════════════════════════════════════════════════════════
#  KO4OS Package Manager - Installer
#  Run as root: bash install.sh
# ═══════════════════════════════════════════════════════════════

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

KO4_PREFIX="${KO4_PREFIX:-/usr/local}"
KO4_HOME="${KO4_HOME:-/var/lib/ko4}"
KO4_CACHE="${KO4_CACHE:-/var/cache/ko4}"
KO4_CONF="/etc/ko4"
KO4_LOG_DIR="/var/log"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

info()  { echo -e "${GREEN}[✔]${NC} $*"; }
warn()  { echo -e "${YELLOW}[!]${NC} $*"; }
error() { echo -e "${RED}[✖]${NC} $*" >&2; }
step()  { echo -e "${CYAN}[→]${NC} ${BOLD}$*${NC}"; }

# ── Checks ──────────────────────────────────────────────────────
if [[ $EUID -ne 0 ]]; then
    error "This installer must be run as root."
    exit 1
fi

echo
echo -e "${BOLD}${CYAN}  KO4OS Package Manager Installer${NC}"
echo -e "  ${CYAN}─────────────────────────────────${NC}"
echo

# Check PHP
step "Checking PHP..."
if ! command -v php &>/dev/null; then
    error "PHP not found. Install PHP 8.1+ with sqlite3 extension."
    echo "  On LFS: compile PHP with --with-sqlite3 --enable-pdo --with-pdo-sqlite"
    exit 1
fi

PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
info "PHP $PHP_VER found."

# Check PHP extensions
MISSING_EXTS=()
for ext in pdo pdo_sqlite json posix pcre; do
    if ! php -m 2>/dev/null | grep -q "^$ext$"; then
        MISSING_EXTS+=("$ext")
    fi
done

if [[ ${#MISSING_EXTS[@]} -gt 0 ]]; then
    error "Missing PHP extensions: ${MISSING_EXTS[*]}"
    echo "  Recompile PHP with these extensions enabled."
    exit 1
fi
info "All required PHP extensions present."

# ── Create directories ───────────────────────────────────────────
step "Creating directory structure..."
for dir in \
    "$KO4_PREFIX/lib/ko4/src/Commands" \
    "$KO4_PREFIX/lib/ko4/src/Core" \
    "$KO4_PREFIX/lib/ko4/src/Package" \
    "$KO4_PREFIX/lib/ko4/src/Build" \
    "$KO4_PREFIX/lib/ko4/src/Repository" \
    "$KO4_HOME/repos" \
    "$KO4_HOME/recipes" \
    "$KO4_HOME/plugins" \
    "$KO4_HOME/hooks" \
    "$KO4_HOME/installed" \
    "$KO4_CACHE/packages" \
    "$KO4_CONF"
do
    mkdir -p "$dir"
done
info "Directories created."

# ── Install files ────────────────────────────────────────────────
step "Installing KO4OS files..."

# Main executable
install -Dm755 "$SCRIPT_DIR/ko4" "$KO4_PREFIX/bin/ko4"

# Source library files
cp -r "$SCRIPT_DIR/src/"* "$KO4_PREFIX/lib/ko4/src/"

# Update KO4_ROOT path in main executable
sed -i "s|dirname(__FILE__)|'$KO4_PREFIX/lib/ko4'|" "$KO4_PREFIX/bin/ko4"

info "Files installed."

# ── Configuration ────────────────────────────────────────────────
step "Installing configuration..."
if [[ -f "$KO4_CONF/ko4.conf" ]]; then
    warn "Config already exists at $KO4_CONF/ko4.conf — not overwriting."
    info "New default config saved as $KO4_CONF/ko4.conf.new"
    install -Dm644 "$SCRIPT_DIR/ko4.conf" "$KO4_CONF/ko4.conf.new"
else
    install -Dm644 "$SCRIPT_DIR/ko4.conf" "$KO4_CONF/ko4.conf"
    info "Config installed at $KO4_CONF/ko4.conf"
fi

# ── Verify ───────────────────────────────────────────────────────
step "Verifying installation..."
if "$KO4_PREFIX/bin/ko4" version &>/dev/null; then
    info "KO4OS installed and working."
else
    error "Installation check failed. Try running: $KO4_PREFIX/bin/ko4 version"
    exit 1
fi

# ── Done ─────────────────────────────────────────────────────────
echo
echo -e "  ${GREEN}${BOLD}KO4OS installed successfully!${NC}"
echo
echo -e "  ${BOLD}Quick Start:${NC}"
echo -e "    ${CYAN}ko4 repo add main https://your-repo.example.com${NC}"
echo -e "    ${CYAN}ko4 sync${NC}"
echo -e "    ${CYAN}ko4 install vim${NC}"
echo -e "    ${CYAN}ko4 build curl${NC}  (from source)"
echo -e "    ${CYAN}ko4 help${NC}"
echo
echo -e "  Config:    ${CYAN}$KO4_CONF/ko4.conf${NC}"
echo -e "  Database:  ${CYAN}$KO4_HOME/ko4.db${NC}"
echo -e "  Cache:     ${CYAN}$KO4_CACHE${NC}"
echo -e "  Recipes:   ${CYAN}$KO4_HOME/recipes${NC}"
echo
