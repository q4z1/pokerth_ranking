#!/bin/sh
cd /var/www/pokerth/pthranking
/usr/bin/pkill -f schedule:work
/usr/local/bin/php artisan schedule:work
echo 'Done.'