<?php

namespace CoreW\Gii\Template\Easy;

use CoreW\Gii\Template\BaseTemplate;
use CoreW\Gii\Template\TemplateInterface;

class DaoInterfaceTemplate extends BaseTemplate implements TemplateInterface
{

    public function getContext(array $args = [])
    {
        $dao = $args['bizId'];
        $daoName = $args['dao'] ?? $args['bizId'];
        $className = "{$daoName}Dao";
        $phpCode = "<?php\n"
            . "\n"
            . "namespace {$this->prefix}\\{$dao}\\Dao;\n"
            . "\n"
            . "use CoreW\\Dao\\AdvancedDaoInterface;\n"
            . "\n"
            . "interface {$className} extends AdvancedDaoInterface\n"
            . "{\n"
            . "\n"
            . "}\n";

        $filename = $args['rootPath'] . "/Dao/{$className}.php";

        return [$filename, $phpCode];
    }
}
