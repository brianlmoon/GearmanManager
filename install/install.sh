#!/bin/sh

# we're going to be mucking about, so we need to be root/sudo'd
if [ "$(id -u)" != "0" ]; then
   echo "This script must be run as root" 1>&2
   exit 1
fi

# where are we now?
WORKING_DIR=$(dirname $(readlink -f $0))

# determine if & which (supported) distro we're running
if [ -f /etc/redhat-release ]; then
    DISTRO="rhel"
elif [-f /etc/debian_version ]; then
    DISTRO="deb"
else
    echo "Only Redhat Enterprise (RHEL) or Debian systems currently supported"
    exit 1
fi

# create and populate installation folder
mkdir -p /usr/local/share/gearman-manager
cp -r ${WORKING_DIR}/../* /usr/local/share/gearman-manager/

# create config folders
mkdir -p /etc/gearman-manager/workers
touch /etc/gearman-manager/config.ini

# symlink proper library wrapper into bin
echo "Which PHP library to use, pecl/gearman or PEAR::Net_Gearman?"
select PHPLIB in "pecl" "pear"; do
    ln -s /usr/local/share/gearman-manager/${PHPLIB}-manager.php /usr/local/bin/gearman-manager
done

# install init script
cp ${WORKING_DIR}/${DISTRO}.sh /etc/init.d/gearman-manager
chmod +x /etc/init.d/gearman-manager

echo "Install ok!  Worker scripts can be installed in /etc/gearman-manager/workers"
echo "Run /etc/init.d/gearman-manager to start and stop"
