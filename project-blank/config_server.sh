#!/bin/bash

php artisan passport:install
php artisan migrate

supervisord