FROM node:22.22.2-bookworm-slim@sha256:9f6d5975c7dca860947d3915877f85607946403fc55349f39b4bc3688448bb6e AS node
FROM php:8.3.33-cli-bookworm@sha256:46af7a501e7cdbdaf6554dbed317d3ea6deb87fdb4101c045265bd25bc089b65
ARG CHROMIUM_VERSION=154.0.8037.57-1~deb12u1
ARG CHROMIUM_ARCHIVE=20260926T000000Z
# Debian's live security mirror drops superseded Chromium builds.
RUN echo "deb [signed-by=/usr/share/keyrings/debian-archive-keyring.gpg check-valid-until=no] https://snapshot.debian.org/archive/debian-security/${CHROMIUM_ARCHIVE}/ bookworm-security main" > /etc/apt/sources.list.d/chromium.list \
    && apt-get -o Acquire::Retries=3 update \
    && apt-get -o Acquire::Retries=3 install -y --no-install-recommends \
    chromium=${CHROMIUM_VERSION} chromium-common=${CHROMIUM_VERSION} bash python3 perl shellcheck procps util-linux \
    openssh-client openssh-server git strace xz-utils ca-certificates \
    && rm -rf /var/lib/apt/lists/*
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
WORKDIR /opt/zfsas-tests
COPY tests/runtime/package*.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund
WORKDIR /work
ENTRYPOINT []
CMD ["bash"]
