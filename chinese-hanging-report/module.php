<?php

declare(strict_types=1);
namespace MyCustomNamespace;

use Fisharebest\Webtrees\Webtrees;


if (defined('WT_MODULES_DIR')) {
    // This is a webtrees 2.x module. it cannot be used with webtrees 1.x. See README.md.
    return;
}



require_once __DIR__ . '/autoload.php';


require __DIR__ . '/src/ChineseHangingModule.php';

return app(ChineseHangingModule::class);
