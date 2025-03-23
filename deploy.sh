#!/bin/bash
set +e

# deploy klms
# ./deploy.sh

BASE_DIR=`dirname $0`

# echo "Build prod assets"
# npm run build

echo "Push data"
rsync -avzh --exclude-from=".deployignore" --delete * -e "ssh -p 822" headshot_ftp@neu.headshot.at:/lan02.kaiserlan.at/

echo "Rsynced data, clearing cache"
ssh -p 822 headshot_ftp@neu.headshot.at "rm -rf /lan02.kaiserlan.at/var/cache/*"

