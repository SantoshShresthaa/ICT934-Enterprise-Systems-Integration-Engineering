#!/usr/bin/env bash
# Compiles and runs the Java ETL (Extract -> Transform -> Load).
#
# Usage (from anywhere):  ./etl/run_etl.sh [datasetDir]
#
# Uses a local JDK if one is installed; otherwise runs inside a temporary
# Docker JDK container, so nothing has to be installed on the machine.
# Database settings come from the project's .env (CRM_DB_*).
set -euo pipefail

cd "$(dirname "$0")/.."

DRIVER_VERSION="9.1.0"
DRIVER_JAR="etl/lib/mysql-connector-j-${DRIVER_VERSION}.jar"
DRIVER_URL="https://repo1.maven.org/maven2/com/mysql/mysql-connector-j/${DRIVER_VERSION}/mysql-connector-j-${DRIVER_VERSION}.jar"

if [ ! -f "$DRIVER_JAR" ]; then
  echo "Downloading MySQL JDBC driver ${DRIVER_VERSION}..."
  mkdir -p etl/lib
  curl -fsSL -o "$DRIVER_JAR" "$DRIVER_URL"
fi

BUILD="mkdir -p etl/build && javac -d etl/build etl/src/*.java"
RUN="java -cp etl/build:${DRIVER_JAR} ETL_Main $*"

if command -v javac >/dev/null 2>&1 && javac -version >/dev/null 2>&1; then
  sh -c "$BUILD && $RUN"
else
  echo "No local JDK found - running the ETL in a Docker JDK container."
  docker run --rm \
    -v "$PWD":/app -w /app \
    -e CRM_DB_HOST_OVERRIDE=host.docker.internal \
    -e TZ="${TZ:-$(readlink /etc/localtime | sed 's#.*/zoneinfo/##')}" \
    -e ETL_MAX_REJECT_PERCENT \
    eclipse-temurin:21-jdk \
    sh -c "$BUILD && $RUN"
fi
