<?php

namespace CoreW\Dao\Annotation;

use Composer\Autoload\ClassLoader;
use Doctrine\Common\Annotations\AnnotationReader;
use Symfony\Component\Finder\Finder;

class AnnotationCacheBuilder
{
    protected $loader;

    public function __construct(ClassLoader $loader)
    {
        $this->loader = $loader;
    }

    public function build(array $namespaces)
    {
        foreach ($namespaces as $namespace) {
            $this->scanNamespace($namespace);
        }
    }

    public function scanNamespace($namespace)
    {
        $cache = [];
        $reader = new AnnotationReader();
        $directories = $this->getNamespaceDirectories($namespace);
        foreach ($directories as $directory) {
            $finder = new Finder();
            $finder->in($directory);

            foreach ($finder->files()->name('*.php') as $file) {
                $class = $namespace . '\\' . str_replace(DIRECTORY_SEPARATOR, '\\', substr($file->getRelativePathname(), 0, -4));

                $classRef = new \ReflectionClass($class);
                $isDao = $classRef->implementsInterface('CoreW\Dao\DaoInterface');
                if (!$isDao) {
                    continue;
                }

                $annotation = $reader->getClassAnnotation($classRef, 'CoreW\Dao\Annotation\CacheStrategy');
                if (empty($annotation)) {
                    continue;
                }

                $cache[$class] = [
                    'strategy'          => $annotation->getName(),
                    'update_rel_fields' => [],
                    'methods'           => [],
                ];

                $methodRefs = $classRef->getMethods(\ReflectionMethod::IS_PUBLIC);
                foreach ($methodRefs as $methodRef) {
                    $annotation = $reader->getMethodAnnotation($methodRef, 'CoreW\Dao\Annotation\RowCache');
                    if (empty($annotation)) {
                        continue;
                    }

                    $cache[$class]['update_rel_fields'] = array_merge($cache[$class]['update_rel_fields'], $annotation->getRelFields());
                    $cache[$class]['methods'][$methodRef->getName()] = [
                        'key' => $annotation->getKey(),
                    ];

                    $params = $methodRef->getParameters();
                }
            }
        }

        //        var_dump($cache);
    }

    public function getNamespaceDirectories($namespace)
    {
        if ('\\' !== substr($namespace, -1)) {
            $namespace .= '\\';
        }

        $directories = [];
        $prefixes = $this->loader->getPrefixesPsr4();
        foreach ($prefixes as $prefix => $prefixDirectories) {
            if (0 !== strpos($namespace, $prefix)) {
                continue;
            }
            $relativeDirectory = str_replace('\\', DIRECTORY_SEPARATOR, substr($namespace, strlen($prefix)));
            foreach ($prefixDirectories as $directory) {
                $directories[] = $directory . DIRECTORY_SEPARATOR . $relativeDirectory;
            }
        }

        return $directories;
    }
}
