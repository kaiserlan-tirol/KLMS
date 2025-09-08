#!/bin/bash
set +e

LANFOLDER="lan03"
BASE_DIR=`dirname $0`


echo "Rsynced data, clearing cache"
ssh -p 822 headshot_ftp@neu.headshot.at "/$LANFOLDER.kaiserlan.at/auto-process-payments.sh"
