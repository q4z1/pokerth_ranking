#!/bin/sh
cd /home/coder/project/www/pokerth/pthranking
/usr/bin/pkill -f schedule:work
/usr/bin/php artisan schedule:work