# AI Mess Detector - Docker Image
# Multi-stage build for minimal image size

# Build stage
FROM php:8.4-cli-alpine AS builder

LABEL maintainer="FractalizeR"
LABEL description="AI Mess Detector - PHP Static Analysis Tool"

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

# Final stage
FROM php:8.4-cli-alpine

LABEL maintainer="FractalizeR"
LABEL description="AI Mess Detector - PHP Static Analysis Tool"

# Install runtime dependencies (if any)
# Currently AIMD only needs PHP CLI with no additional extensions
RUN apk add --no-cache git

# Set working directory for analyzed projects
WORKDIR /app

# Copy application from builder
COPY --from=builder /build /aimd

# Add aimd to PATH
ENV PATH="/aimd/bin:${PATH}"

# Set entrypoint
ENTRYPOINT ["aimd"]

# Default command - analyze current directory
CMD ["analyze", "."]
