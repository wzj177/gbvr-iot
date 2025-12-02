<?php

namespace CoreW\Gii\Template\Easy;

use CoreW\Gii\Template\BaseTemplate;
use CoreW\Gii\Template\TemplateInterface;

class ServiceInterfaceTemplate extends BaseTemplate implements TemplateInterface
{

    public function getContext(array $args = [])
    {
        $service = $args['bizId'];
        $className = "{$service}Service";
        
        // For make-service scene, we need to adjust the namespace
        $namespace = $this->prefix;
        if (!empty($args['scene']) && $args['scene'] === 'make-service' && substr_count($this->prefix, '\\') > 2) {
            // Extract the parent namespace for service generation
            $parts = explode('\\', $this->prefix);
            array_pop($parts); // Remove the last part (business entity name)
            $namespace = implode('\\', $parts);
        }
        
        $phpCode = "<?php\n"
            . "\n"
            . "namespace {$namespace}\\Service;\n"
            . "\n"
            . "interface {$className}\n"
            . "{\n"
            . "    public function get{$service}ById(\$id);\n\n"
            . "    public function count{$service}s(array \$conditions);\n\n"
            . "    public function search{$service}s(array \$conditions, array \$orderBys, \$start, \$limit, \$columns = []);\n"
            . "\n"
            . "    public function create{$service}(array \$fields);\n"
            . "\n"
            . "    public function update{$service}(\$id, array \$fields);\n"
            . "\n"
            . "    public function delete{$service}ById(\$id);\n"
            . "\n"
            . "}\n";

        $filename = $args['rootPath'] . "/Service/{$className}.php";

        return [$filename, $phpCode];
    }
}