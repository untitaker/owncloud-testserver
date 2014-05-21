#!/bin/sh
set -e
DIR="$( cd "$( dirname "$0" )" && pwd )"

mkdir -p "$DIR/owncloud/data/"
mkdir -p "$DIR/owncloud/config/"
cp "$DIR/config/owncloud.config.php" "$DIR/owncloud/config/config.php"
cp "$DIR/config/owncloud.db" "$DIR/owncloud/data/owncloud.db"
