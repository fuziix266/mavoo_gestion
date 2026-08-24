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
    if (!file_exists($file)) continue;
    $content = file_get_contents($file);
    
    // Fix missing colons in php if
    $content = preg_replace('/<\?php\s+(if\s*\([^\{]+\))\s*$/m', '<?php $1: ?' . '>', $content);
    
    // Fix missing colons in php foreach
    $content = preg_replace('/<\?php\s+(foreach\s*\([^\{]+\))\s*$/m', '<?php $1: ?' . '>', $content);

    // Fix @else
    $content = str_replace('@else', '<?php else: ?' . '>', $content);

    // Fix @csrf and @method
    $content = str_replace('@csrf', '', $content);
    $content = preg_replace('/@method\([^)]+\)/', '', $content);

    // Fix @lang('...')
    $content = preg_replace('/@lang\(\'(.*?)\'\)/', '<?=' . ' __(\'$1\') ' . '?>', $content);

    file_put_contents($file, $content);
}
echo "Done replacing second pass tags.\n";
