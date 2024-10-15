<?php

namespace CoreW\Gii\Template\Easy;

use CoreW\Gii\Template\BaseTemplate;
use CoreW\Gii\Template\TemplateInterface;

class ServiceImplTemplate extends BaseTemplate implements TemplateInterface
{

    public function getContext(array $args = [])
    {
        $service = $args['bizId'];
        $className = "{$service}ServiceImpl";
        $daoClass = "{$service}Dao";
        $phpCode = "<?php\n"
            . "\n"
            . "namespace {$this->prefix}\\{$service}\\Service\\Impl;\n"
            . "\n"
            . "use {$this->prefix}\\BaseService;\n"
            . "\n"
            . "use {$this->prefix}\\{$service}\\Service\\{$service}Service;\n"
            . "use {$this->prefix}\\{$service}\\Dao\\{$daoClass};\n"
            . "\n"
            . "class {$className} extends BaseService implements {$service}Service \n"
            . "{\n"
            . "    public function get{$service}ById(\$id)\n"
            . "    {\n"
            . "        return \$this->get{$daoClass}()->get(\$id);\n"
            . "    }\n"
            . "\n"
            . "    public function count{$service}s(array \$conditions)\n"
            . "    {\n"
            . "        return \$this->get{$daoClass}()->count(\$conditions);\n"
            . "    }\n"
            . "\n"
            . "    public function search{$service}s(array \$conditions, array \$orderBys, \$start, \$limit, \$columns = [])\n"
            . "    {\n"
            . "        return \$this->get{$daoClass}()->search(\$conditions, \$orderBys, \$start, \$limit, \$columns);\n"
            . "    }\n"
            . "\n"
            . "    public function create{$service}(array \$fields)\n"
            . "    {\n"
            . "    \n"
            . "    }\n"
            . "\n"
            . "    public function update{$service}(\$id, array \$fields)\n"
            . "    {\n"
            . "    \n"
            . "    }\n"
            . "\n"
            . "    public function delete{$service}ById(\$id)\n"
            . "    {\n"
            . "    \n"
            . "    }\n"
            . "\n"
            . '    /**' . "\n"
            . "      * @return ${daoClass}\n"
            . "      */\n"
            . "    protected function get{$daoClass}()\n"
            . "    {\n"
            . "        return \$this->createDao('${service}:${daoClass}');\n"
            . "    \n"
            . "    }\n"
            . "\n"
            . "}\n";

        $filename = $args['rootPath'] . "/Service/Impl/{$className}.php";

        return [$filename, $phpCode];
    }
}
