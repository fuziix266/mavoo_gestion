<?php
$files = [
    'c:\xampp_php8\htdocs\mavoo_gestion\gestion_laminas\module\Ligas\view\ligas\categorias\index.phtml',
    'c:\xampp_php8\htdocs\mavoo_gestion\gestion_laminas\module\Ligas\view\ligas\nominas\index.phtml',
    'c:\xampp_php8\htdocs\mavoo_gestion\gestion_laminas\module\Ligas\view\ligas\ranking\index.phtml'
];
foreach ($files as $file) {
    if (!file_exists($file)) continue;
    $content = file_get_contents($file);
    // Remover el bloque mal formado o inyectado previamente
    $content = preg_replace('/\/\/ Helpers temporales simulando a Laravel[\s\S]*?(?=<div|<\?php(?!.*function_exists))/m', '', $content);
    file_put_contents($file, $content);
}
echo "Cleaned views";
