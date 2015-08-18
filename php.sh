#!/bin/sh
set -e
DIR="$( cd "$( dirname "$0" )" && pwd )"
echo "$DIR is the own directory."
cd $DIR/owncloud/
tail -F -n0 data/owncloud.log &
exec php -c $DIR/config/php.ini -S 127.0.0.1:8080
