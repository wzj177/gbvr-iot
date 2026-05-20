<?php

namespace CoreW\Gii\Template\Easy;

use CoreW\Gii\Template\BaseTemplate;
use CoreW\Gii\Template\TemplateInterface;

class ExceptionTemplate extends BaseTemplate implements TemplateInterface
{

    public function getContext(array $args = [])
    {
        $exception = $args['bizId'];
        $className = "{$exception}Exception";

        // For make-exception scene, we need to adjust the namespace
        $namespace = $this->prefix;
        if (!empty($args['scene']) && $args['scene'] === 'make-exception' && substr_count($this->prefix, '\\') > 2) {
            // Extract the parent namespace for exception generation
            $parts = explode('\\', $this->prefix);
            array_pop($parts); // Remove the last part (business entity name)
            $namespace = implode('\\', $parts);
        }

        $phpCode = "<?php\n"
            . "\n"
            . "namespace {$namespace}\\Exception;\n"
            . "\n"
            . "use CoreW\\Exception\\AbstractBizException;\n"
            . "\n"
            . "class {$className} extends AbstractBizException \n"
            . "{\n"
            . "    public function __construct(\$code, \$message = null)\n"
            . "    {\n"
            . "        \$this->setMessages();\n"
            . "        parent::__construct(\$code, \$message);\n"
            . "    }\n"
            . "\n"
            . "    /*\n"
            . "     * @return array|array[] \n"
            . "     */\n"
            . "    public function setMessages()\n"
            . "    {\n"
            . "        \$this->messages = [\n"
            . "        \n"
            . "        ];\n"
            . "    }\n"
            . "\n"
            . "}\n";

        $filename = $args['rootPath'] . "/Exception/{$className}.php";

        return [$filename, $phpCode];
    }
}