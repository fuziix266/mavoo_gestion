<?php
$directory = 'c:\\xampp_php8\\htdocs\\mavoo_gestion\\gestion_laminas\\module\\Ligas\\view\\ligas';
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() == 'phtml') {
        $files[] = $file->getPathname();
    }
}

foreach ($files as $file) {
    $content = file_get_contents($file);
    
    // Fix {{ something ? > -> <?= something ? >
    $content = preg_replace('/\{\{\s*(.*?)\s*\?\>/', '<?' . '= $1 ?' . '>', $content);
    
    // Fix {{ something }} -> <?= something ? >
    $content = preg_replace('/\{\{\s*(.*?)\s*\}\}/', '<?' . '= $1 ?' . '>', $content);

    // Some places had {{ asset(...) }} inside Laminas' $this->headLink() ? No wait, {{asset}}
    // Let's just do those two replaces.
    
    file_put_contents($file, $content);
}
echo "Done fixing echo tags.\n";
