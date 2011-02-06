#!/bin/bash

DAEMON=/usr/local/bin/gearman-manager
INIT_D=/etc/init.d/gearman-manager
INSTALL_DIR=/usr/local/share/gearman-manager
CONFIG_DIR=/etc/gearman-manager

# we're going to be mucking about, so we need to be root/sudo'd
if [ "$(id -u)" != "0" ]; then
   echo "This script must be run as root" 1>&2
   exit 1
fi

# where are we now?
WORKING_DIR=$(dirname $(readlink -f $0))

# determine if & which (supported) distro we're running
echo "Detecting linux distro as redhat- or debian-compatible"
if [ -f /etc/redhat-release ]; then
    DISTRO="rhel"
elif [ -f /etc/debian_version ]; then
    DISTRO="deb"
else
    echo "Only Redhat Enterprise (RHEL) or Debian systems currently supported"
    exit 1
fi

# create and populate installation folder
mkdir -p ${INSTALL_DIR}
cp -r ${WORKING_DIR}/../* ${INSTALL_DIR}/
echo "Installing to ${INSTALL_DIR}"

# create config folders
mkdir -p /etc/gearman-manager/workers
cp ${WORKING_DIR}/config.dist.ini ${CONFIG_DIR}/config.ini
echo "Installing configs to ${CONFIG_DIR}"

# symlink proper library wrapper into bin
echo "Which PHP library to use, pecl/gearman or PEAR::Net_Gearman?"
select PHPLIB in "pecl" "pear"; do
    ln -fs ${INSTALL_DIR}/${PHPLIB}-manager.php ${DAEMON}
    echo "Installing executable to ${DAEMON}"
    break
done

# install init script
cp ${WORKING_DIR}/${DISTRO}.sh ${INIT_D}
chmod +x ${INIT_D}
echo "Installing init script to ${INIT_D}"

echo
echo "Install ok!  Run ${INIT_D} to start and stop"
echo "Worker scripts can be installed in ${CONFIG_DIR}/workers, configuration can be edited in ${CONFIG_DIR}/config.ini"
