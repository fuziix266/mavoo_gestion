<?php

declare(strict_types=1);

namespace Dashboard;

class Module
{
    public function getConfig(): array
    {
        return require __DIR__ . '/../config/module.config.php';
    }
}
