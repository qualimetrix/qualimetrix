# Qualimetrix - Docker Image
# Multi-stage build for minimal image size

# Build stage
FROM php:8.4-cli-alpine AS builder

LABEL maintainer="FractalizeR"
LABEL description="Qualimetrix - PHP Static Analysis Tool"

# Install composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /build

# Copy composer files
COPY composer.json composer.lock ./

# Install dependencies (production only, optimized)
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --classmap-authoritative

# Copy source code
COPY . .

# Re-dump the autoloader now that src/ exists.
#
# The install above runs before the source arrives, on purpose: it keeps the
# dependency layer cacheable. But --classmap-authoritative makes the map
# closed, so an autoloader built at that point contains no Qualimetrix class
# and refuses to fall back to PSR-4 scanning — the image built and then died
# on `Class "...ContainerFactory" not found` at every invocation. Nothing
# caught it because no workflow builds this image.
RUN composer dump-autoload \
    --no-dev \
    --no-interaction \
    --optimize \
    --classmap-authoritative

# Final stage
FROM php:8.4-cli-alpine

LABEL maintainer="FractalizeR"
LABEL description="Qualimetrix - PHP Static Analysis Tool"

# pcntl lets PID 1 handle interruption and release report and worker temporaries.
# The base PHP CLI supplies posix and proc_open for process workers.
RUN docker-php-ext-install pcntl && apk add --no-cache git

# The first run creates /cache/qmx as a private directory for its UID; use a
# separate cache volume when running the image as another user.
ENV XDG_CACHE_HOME=/cache
RUN mkdir -m 1777 /cache

# Set working directory for analyzed projects
WORKDIR /app

# Copy application from builder
COPY --from=builder /build /qmx

# Add qmx to PATH
ENV PATH="/qmx/bin:${PATH}"

# Set entrypoint
ENTRYPOINT ["qmx"]

# No default command, deliberately.
#
# This said `analyze` from the repository's first commit and there has never
# been such a command, so `docker run qmx` exited 3 with
# `Command "analyze" is not defined.` The obvious repair -- `check .` -- is
# worse than the bug it fixes: /app is empty unless the caller mounts
# something, and `check` over an empty tree reports "No violations found" and
# exits 0. A forgotten `-v` would turn a loud refusal into a permanently green
# run in someone's CI.
#
# With no CMD the entrypoint prints usage, which is the honest answer to
# "you did not say what to do". Every documented invocation passes a command.
