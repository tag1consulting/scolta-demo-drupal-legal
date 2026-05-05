<?php

// Database configuration
$databases['default']['default'] = [
  'database' => getenv("DB_NAME"),
  'username' => getenv("DB_USERNAME"),
  'password' => getenv("DB_PASSWORD"),
  'host' => getenv("DB_HOST"),
  'port' => getenv("DB_PORT"),
  'driver' => 'mysql',
  'charset' => 'utf8mb4',
  'collation' => 'utf8mb4_general_ci',
  'prefix' => '',
];

$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT');

$settings['file_private_path'] = '/var/www/html/web/sites/default/private';

$config['automated_cron.settings']['interval'] = 0;
