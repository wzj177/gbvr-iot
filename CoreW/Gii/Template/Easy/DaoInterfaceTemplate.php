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
        
        // For make-dao scene, we need to adjust the namespace
        $namespace = $this->prefix;
        if (!empty($args['scene']) && $args['scene'] === 'make-dao' && substr_count($this->prefix, '\\') > 2) {
            // Extract the parent namespace for dao generation
            $parts = explode('\\', $this->prefix);
            array_pop($parts); // Remove the last part (business entity name)
            $namespace = implode('\\', $parts);
        }
        
        $phpCode = "<?php\n"
            . "\n"
            . "namespace {$namespace}\\Dao;\n"
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