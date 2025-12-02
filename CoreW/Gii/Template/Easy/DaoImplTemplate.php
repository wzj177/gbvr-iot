<?php

namespace CoreW\Gii\Template\Easy;

use CoreW\Gii\Template\BaseTemplate;
use CoreW\Gii\Template\TemplateInterface;
use support\utils\ArrayToolkit;

class DaoImplTemplate extends BaseTemplate implements TemplateInterface
{

    /**
     * @param array $args
     * @return string
     */
    public function getContext(array $args = [])
    {
        $dao = $args['bizId'];
        $daoName = $args['dao'] ?? $args['bizId'];
        $tableName = $args['prefix'] . ($args['tableName'] ?? $this->uncamelize($daoName));
        $className = "{$daoName}DaoImpl";
        $declares = $this->parseDeclares($args['declares'] ?? [], $tableName);
        $declaresStr = $this->splitDeclares($declares);
        
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
            . "namespace {$namespace}\\Dao\\Impl;\n"
            . "\n"
            . "use CoreW\\Dao\\AdvancedDaoImpl;\n"
            . "use {$namespace}\\Dao\\{$daoName}Dao;\n"
            . "\n"
            . "class {$className} extends AdvancedDaoImpl implements {$daoName}Dao \n"
            . "{\n"
            . "\n"
            . "    protected " . '$table = ' . "'{$tableName}';\n"
            . "\n"
            . "    public function declares():array\n"
            . "    {\n"
            . "        {$declaresStr}"
            . "    } \n"
            . "}\n";

        $filename = $args['rootPath'] . "/Dao/Impl/{$className}.php";

        return [$filename, $phpCode];
    }

    protected function splitDeclares($declares)
    {
        $str = "return [\n";
        if (!empty($declares)) {
            foreach ($declares as $key => $declare) {
                $subStr = "            '{$key}' => ";
                if (is_string($declare)) {
                    $subStr .= " '{$declares}',\n";
                } elseif (is_array($declare)) {
                    $subStr .= "[ \n";
                    foreach ($declare as $sKey => $sVal) {
                        if (is_numeric($sKey)) {
                            $subStr .= "                '{$sVal}',\n";
                        } else {
                            $subStr .= "                '{$sKey}' => '{$sVal}',\n";
                        }
                    }
                    $subStr .= "           ], \n";
                }

                $str .= $subStr;
            }
        }

        $str .= "        ];\n";

        return $str;
    }

    protected function parseDeclares($declares, $tableName)
    {
        empty($declares['serializes']) && $declares['serializes'] = [];
        empty($declares['orderbys']) && $declares['orderbys'] = [];
        empty($declares['conditions']) && $declares['conditions'] = [];
        $tableDescriptions = $this->getTableDescriptions($tableName);
        $fields = ArrayToolkit::column($tableDescriptions, 'Field');
        if (in_array('id', $fields)) {
            $declares['orderbys'][] = 'id';
            array_push($declares['conditions'], 'id = :id', 'id > :id_GT', 'id IN (:ids)', 'id NOT IN (:noIds)');
        }

        if (in_array('title', $fields)) {
            array_push($declares['conditions'], 'title = :title', 'title LIKE :titleLike', 'title PRE_LIKE :titlePreTitle');
        }

        if (in_array('createdTime', $fields)) {
            $declares['orderbys'][] = 'createdTime';
            $declares['timestamps'][] = 'createdTime';
            array_push($declares['conditions'], 'createdTime >= :startTime', 'createdTime <= :endTime');
        }

        if (in_array('updatedTime', $fields)) {
            $declares['orderbys'][] = 'updatedTime';
            $declares['timestamps'][] = 'updatedTime';
        }

        return $declares;
    }

    protected function getTableDescriptions($tableName)
    {
        return $this->biz['db']->fetchAll("DESC `{$tableName}`;") ?? [];
    }
}