<?php
$file = 'c:\xampp_php8\htdocs\mavoo_gestion\gestion_laminas\module\Ligas\view\ligas\nominas\index.phtml';
$content = file_get_contents($file);
$content = preg_replace_callback(
    '/route\("sistema\.mod\.padel\.gestion\.nominas\.([^"]+)"(?:,\s*\(\$evento\[\'uuid\'\]\s*\?\?\s*\'\'\))?\)/',
    function($matches) {
        $accion = str_replace('.', '-', $matches[1]);
        return "\$this->url('ligas.nominas', ['deporte' => \$deporte ?? 'padel', 'accion' => '$accion', 'uuid' => \$evento['uuid'] ?? 'fake-uuid'])";
    },
    $content
);
file_put_contents($file, $content);
echo "Replaced route in nominas\n";
