<?php
$dir = 'c:\\xampp_php8\\htdocs\\mavoo_gestion\\gestion_laminas\\module\\Ligas\\src\\Controller';
$files = glob($dir . '/*.php');
foreach ($files as $file) {
    if (strpos($file, 'Factory.php') !== false || strpos($file, 'IndexController.php') !== false || strpos($file, 'AjustesController.php') !== false) {
        continue;
    }
    
    $content = file_get_contents($file);
    if (strpos($content, '$menuEV') !== false) {
        continue;
    }
    
    $mock = "
        // Mock menuEV
        \$menuEV = (object)[
            'uuid' => \$uuid ?? '1234',
            'sedes' => true,
            'categorias' => true,
            'nominas' => true,
            'fixture' => true,
            'ranking' => true,
            'notificaciones' => true,
            'logs' => true,
        ];
";

    $content = str_replace('return new ViewModel([', $mock . "\n        return new ViewModel([\n            'menuEV' => \$menuEV,", $content);
    file_put_contents($file, $content);
}
echo "Done injecting menuEV.\n";
