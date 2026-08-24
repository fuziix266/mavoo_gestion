<?php
chdir(__DIR__);
$file = 'module/Admin/view/admin/index/dios.phtml';
$content = file_get_contents($file);

// 1. Blade Comments {{-- ... --}} -> <!-- ... -->
$content = preg_replace('/\{\{--(.*?)--\}\}/s', '<!--$1-->', $content);

// 2. Extends and sections
$content = preg_replace('/@extends\([^\)]+\)/', '<?php extract($this->vars()->getArrayCopy()); ?>', $content);
$content = preg_replace('/@section\(\'title\',\s*(.*?)\)/', '<?php $this->headTitle($1); ?>', $content);
$content = preg_replace('/@section\(\'css\'\)/', '<?php $this->headStyle()->captureStart(); ?>', $content);
$content = preg_replace('/@section\(\'content\'\)/', '<?php $this->headStyle()->captureEnd(); ?>', $content);

// 3. Foreach loops
$content = preg_replace('/@foreach\s*\((.*?)\)/', '<?php foreach($1) { ?>', $content);
$content = preg_replace('/@endforeach/', '<?php } ?>', $content);

$content = preg_replace('/@forelse\s*\((.*?)\)/', '<?php if (!empty(array_filter((array)$1))) { foreach($1) { ?>', $content);
$content = preg_replace('/@empty/', '<?php } } else { ?>', $content);
$content = preg_replace('/@endforelse/', '<?php } ?>', $content);

// 4. if/else
$content = preg_replace('/@if\s*\((.*?)\)/', '<?php if($1) { ?>', $content);
$content = preg_replace('/@elseif\s*\((.*?)\)/', '<?php } elseif($1) { ?>', $content);
$content = preg_replace('/@else/', '<?php } else { ?>', $content);
$content = preg_replace('/@endif/', '<?php } ?>', $content);

// 5. Includes
$content = preg_replace("/@include\('sistema\.dios\.dios\.editar-modal',\s*\[(.*?)\]\)/s", '<?= $this->partial("admin/index/dios-modal", [$1]) ?>', $content);

// 6. Outputs {{ ... }} -> <?= ... ?>
$content = preg_replace('/\{\{\s*(.*?)\s*\}\}/s', '<?= $1 ?>', $content);

// 7. Route and Asset helpers
$content = preg_replace('/asset\((.*?)\)/', '$this->basePath($1)', $content);
$content = preg_replace('/route\((.*?)\)/', '$this->basePath("admin/dios/ceo/" . $1)', $content); // Placeholder URL
$content = preg_replace('/@csrf/', '', $content);
$content = preg_replace('/@method\([^\)]+\)/', '', $content);

// 8. Script section
$content = preg_replace('/@section\(\'script\'\)/', '<?php $this->inlineScript()->captureStart(); ?>', $content);
$content = preg_replace('/@endsection/', '<?php $this->inlineScript()->captureEnd(); ?>', $content);

// Fix trailing endsection from content
$content = preg_replace('/<\?php \$this->inlineScript\(\)->captureEnd\(\); \?>(?!\s*<\?php)/is', '<?php $this->inlineScript()->captureEnd(); ?>', $content);

file_put_contents($file, $content);
echo "Done replacing dios.phtml";
