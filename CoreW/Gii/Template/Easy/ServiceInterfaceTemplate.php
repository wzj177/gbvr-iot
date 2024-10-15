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
        $phpCode = "<?php\n"
            . "\n"
            . "namespace {$this->prefix}\\{$service}\\Service;\n"
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
