#!/bin/bash
set +e

# deploy klms
# ./deploy.sh

LANFOLDER="lan05"
BASE_DIR=`dirname $0`


# echo "Build prod assets"
# npm run build

echo "Push data to $LANFOLDER.kaiserlan.at"
rsync -avzh --exclude-from=".deployignore" --delete * -e "ssh -p 822" headshot_ftp@neu.headshot.at:/$LANFOLDER.kaiserlan.at/

echo -e "\nRsynced data, clearing cache\n"
ssh -p 822 headshot_ftp@neu.headshot.at "rm -rf /$LANFOLDER.kaiserlan.at/var/cache/* && /.phpenv/versions/8.3/bin/php /$LANFOLDER.kaiserlan.at/bin/console doctrine:schema:update --force --complete && /.phpenv/versions/8.3/bin/php /$LANFOLDER.kaiserlan.at/bin/console cache:clear"

# commands
# bash-4.4$ /.phpenv/versions/8.3/bin/php bin/console cache:clear
# /.phpenv/versions/8.3/bin/php bin/console doctrine:schema:update --force --complete
# ssh -p 822 headshot_ftp@neu.headshot.at "tail -f /lan04.kaiserlan.at/var/log/idm_manager.log"