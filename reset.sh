#!/bin/sh
set -e
DIR="$( cd "$( dirname "$0" )" && pwd )"

cd "$DIR"

mkdir -p owncloud/data/
mkdir -p owncloud/config/
cp config/owncloud.config.php owncloud/config/config.php
cp config/owncloud.db owncloud/data/owncloud.db
