<?php
use Laminas\Session\Container;
require 'vendor/autoload.php';

$session = new Container('auth');
$session->identity = [
    'id' => 1,
    'uuid' => 'admin-1234',
    'name' => 'Admin User',
    'email' => 'admin@gmail.com'
];
$session->roles = ['admin'];
$session->permissions = [];

echo "Superadmin login session initialized in session.\n";
