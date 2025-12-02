<?php


namespace CoreW\Gii\Template\Easy;


use CoreW\Gii\GiiException;
use CoreW\Gii\Template\BaseProcessor;
use CoreW\Gii\Template\TemplateInterface;
use Illuminate\Support\Str;

class EasyProcessor extends BaseProcessor
{
    private array $templates = [
        'daoInterface' => DaoInterfaceTemplate::class,
        'daoImpl' => DaoImplTemplate::class,
        'serviceInterface' => ServiceInterfaceTemplate::class,
        'serviceImpl' => ServiceImplTemplate::class,
        'exception' => ExceptionTemplate::class
    ];

    public function getTemplates(): array
    {
        return $this->templates;
    }

    public function render(array $args = []): string
    {
        $args['bizId'] = ucwords($args['bizId']);
        if (Str::contains($this->namespacePrefix, 'Plugins')) {
            list($rootName, $packageName, $pluginName) = explode("\\", $this->namespacePrefix);
            $rootName = str_replace('Plugins', 'vendor', $rootName);
            $path = $rootName . '/' . strtolower($packageName) . '/' . strtolower($this->uncamelize($pluginName)) . '/src/Business';

        } elseif (Str::contains($this->namespacePrefix, "CoreW\\")) {
            $path = str_replace("\\", "/", ltrim($this->namespacePrefix, "App\\"));
        } else {
            throw GiiException::pathNotOpen();
        }

        // For make-service, make-exception and make-dao scenes, we don't append bizId to the path
        if (!empty($args['scene']) && in_array($args['scene'], ['make-service', 'make-exception', 'make-dao'])) {
            $path = base_path() . DIRECTORY_SEPARATOR . $path;
        } else {
            $path = base_path() . DIRECTORY_SEPARATOR . $path . DIRECTORY_SEPARATOR . $args['bizId'];
        }

        $args['rootPath'] = $path;
        $templates = $this->templates;
        if (!empty($args['templates']) && is_array($args['templates'])) {
//            $templates = array_merge($args['templates'], $templates);
            $templates = $args['templates'];
            unset($args['templates']);
        }

        if (is_dir($path) && isset($templates['serviceInterface']) && empty($args['scene'])) {
            throw GiiException::serviceExisted($path, "the {$args['bizId']} biz already exist!");
        }

        if (!empty($args['useDao']) && strtolower($args['useDao']) === 'n') {
            unset($templates['daoInterface'], $templates['daoImpl']);
        }
        
        // Handle service creation scene
        if (!empty($args['scene']) && $args['scene'] === 'make-service') {
            unset($templates['daoInterface'], $templates['daoImpl'], $templates['exception']);
        }
        
        // Handle exception creation scene
        if (!empty($args['scene']) && $args['scene'] === 'make-exception') {
            unset($templates['daoInterface'], $templates['daoImpl'], $templates['serviceInterface'], $templates['serviceImpl']);
        }
        
        // Handle dao creation scene
        if (!empty($args['scene']) && $args['scene'] === 'make-dao') {
            unset($templates['serviceInterface'], $templates['serviceImpl'], $templates['exception']);
        }
        $templates = array_values($templates);
        try {
            $this->renderPath($path);
            foreach ($templates as $template) {
                if (class_exists($template)) {
                    /** @var $templateObj TemplateInterface */
                    $templateObj = new $template($this->namespacePrefix, $this->biz);
                    list($filename, $content) = $templateObj->getContext($args);
                    if (!empty($filename)) {
                        file_put_contents($filename, $content);
                    }
                }
            }

            return $path;
        } catch (\Exception $e) {
            if (empty($args['scene'])) {
                shell_exec("rm -rf {$path}");
            }
            throw $e;
        }
    }

    protected function renderPath($root)
    {
        !is_dir($root) && mkdir($root, 0755, true);
        foreach ($this->defaultSubPaths as $path => $subPath) {
            $dir = $root . DIRECTORY_SEPARATOR . $path;
            !is_dir($dir) && mkdir($dir);
            foreach ($subPath as $value) {
                $dir = $root . DIRECTORY_SEPARATOR . $path . DIRECTORY_SEPARATOR . $value;
                !is_dir($dir) && mkdir($dir);
            }
        }
    }

    public function uncamelize($camelCaps, $separator = '-')
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', "$1" . $separator . "$2", $camelCaps));
    }
}
