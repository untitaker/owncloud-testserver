#!/bin/sh
set -e
DIR="$( cd "$( dirname "$0" )" && pwd )"
echo "$DIR is the own directory."
cd $DIR/owncloud/

trap 'kill $(jobs -p) &> /dev/null' TERM EXIT
tail -F -n0 data/owncloud.log &
php -c $DIR/config/php.ini -S 127.0.0.1:8080 &
wait
