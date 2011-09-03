#!/bin/bash

# Gearman worker manager

### BEGIN INIT INFO
# Provides:          gearman-manager
# Required-Start:    $network $remote_fs $syslog
# Required-Stop:     $network $remote_fs $syslog
# Default-Start:     2 3 4 5
# Default-Stop:      0 1 6
# Short-Description: Start daemon at boot time
# Description:       Enable gearman manager daemon
### END INIT INFO

# Source function library.
. /etc/rc.d/init.d/functions

##PATH##
DAEMON=/usr/local/bin/gearman-manager
PIDDIR=/var/run/gearman
PIDFILE=${PIDDIR}/manager.pid
LOGFILE=/var/log/gearman-manager.log
CONFIGDIR=/etc/gearman-manager
GEARMANUSER="gearmand"
PARAMS="-c ${CONFIGDIR}/config.ini"

RETVAL=0

start() {
        echo -n $"Starting gearman-manager: "
        if ! test -d ${PIDDIR}
        then
          mkdir ${PIDDIR}
          chown ${GEARMANUSER} ${PIDDIR}
        fi
        daemon --pidfile=$PIDFILE $DAEMON \
            -P $PIDFILE \
            -l $LOGFILE \
            -u $GEARMANUSER \
            -d \
            $PARAMS
        RETVAL=$?
        echo
        return $RETVAL
}

stop() {
        echo -n $"Stopping gearman-manager: "
        killproc -p $PIDFILE -TERM $DAEMON
        RETVAL=$?
        echo
}

# See how we were called.
case "$1" in
  start)
        start
        ;;
  stop)
        stop
        ;;
  status)
        status -p $PIDFILE $DAEMON
        RETVAL=$?
        ;;
  restart|reload)
        stop
        start
        ;;
  condrestart|try-restart)
        if status -p $PIDFILE $DAEMON >&/dev/null; then
                stop
                start
        fi
        ;;
  *)
        echo $"Usage: $prog {start|stop|restart|reload|condrestart|status|help}"
        RETVAL=3
esac

exit $RETVAL
