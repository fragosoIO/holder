#!/bin/sh
set -e
cd /repo/api
php ./yii migrate:up --force-yes
php ./yii holder:bootstrap
exec "$@"
