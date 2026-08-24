<?php
$content = file_get_contents('c:\xampp_php8\htdocs\mavoo_gestion\gestion_laminas\helpers.php');
$content = preg_replace('/if \(\!function_exists\(\'asset\'\)\) \{[\s\S]*?\}\s*\}/', '', $content, 1, $count);
$content = preg_replace('/if \(\!function_exists\(\'route\'\)\) \{[\s\S]*?\}\s*\}/', '', $content, 1, $count);
file_put_contents('c:\xampp_php8\htdocs\mavoo_gestion\gestion_laminas\helpers.php', $content);
echo "Cleaned duplicates";
