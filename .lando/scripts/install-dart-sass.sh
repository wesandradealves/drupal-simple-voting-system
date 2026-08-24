#!/usr/bin/env bash
set -euo pipefail

# Ported from docker/php/Dockerfile. The release tarball has to be extracted whole:
# the `sass` entry point is a shell wrapper that execs "$path/src/dart", so copying
# only the wrapper produces a binary that dies looking for its own runtime.

SASS_VERSION="${SASS_VERSION:-1.103.1}"
SASS_HOME="/opt/dart-sass"
SASS_LINK="/usr/local/bin/sass"
SASS_URL="https://github.com/sass/dart-sass/releases/download/${SASS_VERSION}/dart-sass-${SASS_VERSION}-linux-x64.tar.gz"

if [ -x "${SASS_HOME}/sass" ] && "${SASS_LINK}" --version 2>/dev/null | grep -qx "${SASS_VERSION}"; then
  echo "Dart Sass ${SASS_VERSION} is already installed."
  exit 0
fi

echo "Installing Dart Sass ${SASS_VERSION} from ${SASS_URL}"
rm -rf "${SASS_HOME}"
curl -fsSL -o /tmp/dart-sass.tar.gz "${SASS_URL}"
mkdir -p /opt
tar -xzf /tmp/dart-sass.tar.gz -C /opt
rm -f /tmp/dart-sass.tar.gz
ln -sf "${SASS_HOME}/sass" "${SASS_LINK}"
"${SASS_LINK}" --version
