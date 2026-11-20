<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

final class KnowledgeBase extends BaseConfig
{
    public string $directory = WRITEPATH . 'knowledgebase';
    public string $backend = 'json';
}
