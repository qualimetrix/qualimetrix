#!/bin/bash
set -euo pipefail

# ============================================================================
# Claude Code Web Environment Initialization Script
# ============================================================================
# Automatically installs tools and dependencies for the AI agent
# to work with the AI Mess Detector PHP project.
#
# Runs automatically on session start in Claude Code on the Web
# via the SessionStart hook (.claude/settings.json).
# ============================================================================

# Colors for logging
readonly RED='\033[0;31m'
readonly GREEN='\033[0;32m'
readonly YELLOW='\033[1;33m'
readonly BLUE='\033[0;34m'
readonly NC='\033[0m' # No Color

# Logging
log_info() {
    echo -e "${BLUE}[INFO]${NC} $*"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $*"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $*"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $*"
}

# ============================================================================
# 1. Environment check
# ============================================================================

# Run ONLY in the remote environment (Claude Code on the Web)
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
    log_info "Script running locally. Skipping initialization."
    exit 0
fi

log_info "Initializing environment for Claude Code on the Web..."

# ============================================================================
# 2. System utilities installation
# ============================================================================

log_info "Checking system utilities..."

# List of required packages
REQUIRED_TOOLS=(
    "apt-utils"     # APT utilities (eliminates debconf warnings)
    "tree"          # Directory structure visualization
    "shellcheck"    # Bash script linter
    "gh"            # GitHub CLI
    "bat"           # cat with syntax highlighting
    "fd-find"       # Fast file search
    "git-delta"     # Improved git diff
)

# Check which packages need to be installed
MISSING_TOOLS=()
for tool in "${REQUIRED_TOOLS[@]}"; do
    # apt-utils does not provide a command, check via dpkg
    if [ "$tool" = "apt-utils" ]; then
        if ! dpkg -l | grep -q "^ii  apt-utils"; then
            MISSING_TOOLS+=("$tool")
        fi
        continue
    fi

    # Some packages in Debian/Ubuntu have commands with different names
    CMD_NAME="$tool"
    if [ "$tool" = "fd-find" ]; then
        CMD_NAME="fdfind"
    elif [ "$tool" = "bat" ]; then
        CMD_NAME="batcat"
    elif [ "$tool" = "git-delta" ]; then
        CMD_NAME="delta"
    fi

    if ! command -v "$CMD_NAME" &> /dev/null; then
        MISSING_TOOLS+=("$tool")
    fi
done

# Install missing packages
if [ ${#MISSING_TOOLS[@]} -gt 0 ]; then
    log_info "Installing: ${MISSING_TOOLS[*]}"

    # Create a temporary directory with correct permissions for apt
    APT_TMP_DIR=$(mktemp -d)
    chmod 1777 "$APT_TMP_DIR"

    # Update package list (quietly)
    TMPDIR="$APT_TMP_DIR" apt-get update -qq || {
        log_error "Failed to update package list"
        rm -rf "$APT_TMP_DIR"
        exit 1
    }

    # Install apt-utils first
    APT_UTILS_IN_LIST=false
    for tool in "${MISSING_TOOLS[@]}"; do
        if [ "$tool" = "apt-utils" ]; then
            APT_UTILS_IN_LIST=true
            break
        fi
    done

    if [ "$APT_UTILS_IN_LIST" = true ]; then
        log_info "Installing apt-utils..."
        TMPDIR="$APT_TMP_DIR" DEBIAN_FRONTEND=noninteractive apt-get install -y -qq apt-utils || {
            log_error "Failed to install apt-utils"
            rm -rf "$APT_TMP_DIR"
            exit 1
        }
        # Remove apt-utils from the installation list
        REMAINING_TOOLS=()
        for tool in "${MISSING_TOOLS[@]}"; do
            if [ "$tool" != "apt-utils" ]; then
                REMAINING_TOOLS+=("$tool")
            fi
        done
        MISSING_TOOLS=("${REMAINING_TOOLS[@]}")
    fi

    # Install remaining packages
    if [ ${#MISSING_TOOLS[@]} -gt 0 ]; then
        TMPDIR="$APT_TMP_DIR" DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "${MISSING_TOOLS[@]}" || {
            log_error "Failed to install packages: ${MISSING_TOOLS[*]}"
            rm -rf "$APT_TMP_DIR"
            exit 1
        }
    fi

    rm -rf "$APT_TMP_DIR"
    log_success "All required packages installed"
else
    log_success "All system utilities already installed"
fi

# ============================================================================
# 3. Configure git to use delta
# ============================================================================

if command -v delta &> /dev/null; then
    log_info "Configuring git to use delta..."

    git config --global core.pager delta 2>/dev/null || true
    git config --global interactive.diffFilter "delta --color-only" 2>/dev/null || true
    git config --global delta.navigate true 2>/dev/null || true
    git config --global delta.light false 2>/dev/null || true
    git config --global delta.line-numbers true 2>/dev/null || true

    log_success "Git configured to use delta"
fi

# ============================================================================
# 4. PHP extensions installation
# ============================================================================

log_info "Checking PHP extensions..."

# Check PHP
if ! command -v php &> /dev/null; then
    log_error "PHP not found in the system!"
    exit 1
fi

# Determine PHP version for paths
PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
PHP_INI_DIR="/etc/php/${PHP_VERSION}/cli/conf.d"

# Install PCOV (fast code coverage driver)
# The base image has pcov.ini but pcov.so is missing - needs to be installed
if ! php -m 2>/dev/null | grep -q "^pcov$"; then
    log_info "Installing PCOV for code coverage..."

    APT_TMP_DIR=$(mktemp -d)
    chmod 1777 "$APT_TMP_DIR"

    # Method 1: Try to install via apt package php-pcov (most reliable)
    PCOV_INSTALLED=false

    # Update apt if not already updated
    TMPDIR="$APT_TMP_DIR" apt-get update -qq 2>/dev/null || true

    # Try to install php{version}-pcov or php-pcov
    for pkg in "php${PHP_VERSION}-pcov" "php-pcov"; do
        if apt-cache show "$pkg" &>/dev/null; then
            log_info "Installing $pkg via apt..."
            if TMPDIR="$APT_TMP_DIR" DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$pkg" 2>/dev/null; then
                PCOV_INSTALLED=true
                log_success "PCOV installed via apt ($pkg)"
                break
            fi
        fi
    done

    # Method 2: If apt package is unavailable, try PECL
    if [ "$PCOV_INSTALLED" = false ]; then
        log_info "apt package unavailable, trying PECL..."

        # Install build dependencies
        TMPDIR="$APT_TMP_DIR" DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
            "php${PHP_VERSION}-dev" \
            php-pear \
            build-essential \
            2>/dev/null || true

        # Install PCOV via PECL
        if command -v pecl &> /dev/null; then
            # Answer "no" to the enable question (we'll enable manually)
            echo "" | pecl install pcov 2>/dev/null && PCOV_INSTALLED=true || true
        fi

        # Enable extension if installed via PECL
        if [ "$PCOV_INSTALLED" = true ] && [ -d "$PHP_INI_DIR" ]; then
            echo "extension=pcov.so" > "${PHP_INI_DIR}/99-pcov.ini" 2>/dev/null || true
        fi
    fi

    rm -rf "$APT_TMP_DIR"

    # Final check
    if php -m 2>/dev/null | grep -q "^pcov$"; then
        log_success "PCOV installed and activated"
    else
        log_warning "Failed to install PCOV. Code coverage will use fallback driver"
    fi
else
    log_success "PCOV already installed"
fi

# Check Composer
if ! command -v composer &> /dev/null; then
    log_error "Composer not found in the system!"
    exit 1
fi

# ============================================================================
# 5. Install composer dependencies
# ============================================================================

log_info "Checking composer dependencies..."

# Check for vendor directory
if [ ! -d "vendor" ]; then
    log_info "Installing composer dependencies..."

    composer install --no-interaction --prefer-dist || {
        log_error "Failed to install composer dependencies"
        exit 1
    }

    log_success "Composer dependencies installed"
else
    log_success "Composer dependencies already installed"
fi

# ============================================================================
# 6. Check critical files and directories
# ============================================================================

log_info "Checking project structure..."

CRITICAL_DIRS=(
    "src/Core"
    "src/Analysis"
    "src/Metrics"
    "src/Rules"
    "src/Reporting"
    "src/Configuration"
    "src/Infrastructure"
    "tests"
    "docs"
)

for dir in "${CRITICAL_DIRS[@]}"; do
    if [ ! -d "$dir" ]; then
        log_warning "Directory $dir not found"
    fi
done

CRITICAL_FILES=(
    "composer.json"
    "phpunit.xml.dist"
    "phpstan.neon"
    "deptrac.yaml"
    "CLAUDE.md"
)

for file in "${CRITICAL_FILES[@]}"; do
    if [ ! -f "$file" ]; then
        log_warning "File $file not found"
    fi
done

# ============================================================================
# 7. Configure git hooks
# ============================================================================

if [ -d ".githooks" ]; then
    log_info "Configuring git hooks..."
    git config core.hooksPath .githooks 2>/dev/null || true
    log_success "Git hooks configured"
fi

# ============================================================================
# 8. Finalization
# ============================================================================

log_success "Environment ready!"

# Print installed tool versions
log_info ""
log_info "Installed tools:"
log_info "  PHP: $(php --version | head -n1)"
log_info "  PHP extensions: $(php -m | grep -E '^(pcov|xdebug)$' | tr '\n' ' ' || echo 'none')"
log_info "  Composer: $(composer --version | cut -d' ' -f3)"
log_info "  Git: $(git --version | cut -d' ' -f3)"
log_info "  tree: $(tree --version | head -n1)"
log_info "  shellcheck: $(shellcheck --version | grep version | cut -d' ' -f2)"
log_info "  gh: $(gh --version | head -n1 | cut -d' ' -f3)"
log_info "  bat: $(batcat --version 2>/dev/null | cut -d' ' -f2 || bat --version 2>/dev/null | cut -d' ' -f2 || echo 'N/A')"
log_info "  fd: $(fdfind --version | cut -d' ' -f2)"
log_info "  delta: $(delta --version | cut -d' ' -f2)"
log_info ""

# Print available composer commands
log_info "Available composer commands:"
log_info "  composer test         - Run PHPUnit tests"
log_info "  composer test:coverage - Tests with HTML coverage"
log_info "  composer phpstan      - Static analysis (level 8)"
log_info "  composer deptrac      - Check architectural layers"
log_info "  composer check        - All checks (test + phpstan + deptrac)"
log_info "  composer cs-fix       - Fix code style"
log_info ""

exit 0
