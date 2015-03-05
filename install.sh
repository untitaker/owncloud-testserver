#!/bin/sh
set -e
OWNCLOUD_VERSION="8.0.1"
OC_DOWNLOAD_URL="http://download.owncloud.org/community/owncloud-${OWNCLOUD_VERSION}.tar.bz2"
DIR="$( cd "$( dirname "$0" )" && pwd )"

echo "install.sh: Own directory is $DIR"

if [ "$TRAVIS" = "true" ]; then
    # http://doc.owncloud.org/server/6.0/admin_manual/installation/installation_source.html#installation-of-packages-on-ubuntu-12-04-4-lts-server
    sudo add-apt-repository -y ppa:ondrej/php5-oldstable
    # who cares if one or two repos are down. As long as i'm able to install
    # these packages...
    sudo apt-get update || true

    sudo apt-get install \
        php5 php5-cli \
        php5-gd php5-json php5-sqlite php5-curl \
        php5-intl php5-mcrypt
fi

cd "$DIR"

if [ ! -d owncloud ]; then
    if [ ! -f owncloud.tar.bz2 ]; then
        echo "Downloading owncloud version: $OWNCLOUD_VERSION"
        wget "$OC_DOWNLOAD_URL" -O owncloud.tar.bz2
    fi
    echo "Extracting ownCloud"
    tar xjf owncloud.tar.bz2
fi

sh $DIR/reset.sh
