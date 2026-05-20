<?php


namespace app\command;


use mysql_xdevapi\Exception;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webman\Console\Commands\MakeControllerCommand;
use Webman\Console\Util;

class MakeResourceCommand extends MakeControllerCommand
{
    protected static $defaultName = 'make:resource';
    protected static $defaultDescription = 'Make resource';


    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Resource name');
        $this->addArgument('service', InputArgument::OPTIONAL, 'Service name(eg:product/catalog)');
        $this->setHelp("eg:  make:resource UserController | make:resource ProductController product | make:resource ProductCatalogController product/productCatalog");
    }


    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        $name = $input->getArgument('name');
        $service = $input->getArgument('service');
        $output->writeln("Make controller $name");
        $suffix = config('app.controller_suffix', '');

        if ($suffix && !strpos($name, $suffix)) {
            $name .= $suffix;
        }

        $name = str_replace('\\', '/', $name);
        if (!($pos = strrpos($name, '/'))) {
            $name = ucfirst($name);
            $controller_str = Util::guessPath(app_path(), 'controller') ? : 'controller';
            $file = app_path() . "/$controller_str/$name.php";
            $namespace = $controller_str === 'Controller' ? 'App\Controller' : 'app\controller';
        } else {
            $name_str = substr($name, 0, $pos);
            if ($real_name_str = Util::guessPath(app_path(), $name_str)) {
                $name_str = $real_name_str;
            } else if ($real_section_name = Util::guessPath(app_path(), strstr($name_str, '/', true))) {
                $upper = strtolower($real_section_name[0]) !== $real_section_name[0];
            } else if ($real_base_controller = Util::guessPath(app_path(), 'controller')) {
                $upper = strtolower($real_base_controller[0]) !== $real_base_controller[0];
            }
            $upper = $upper ?? strtolower($name_str[0]) !== $name_str[0];
            if ($upper && !$real_name_str) {
                $name_str = preg_replace_callback('/\/([a-z])/', function ($matches) {
                    return '/' . strtoupper($matches[1]);
                }, ucfirst($name_str));
            }
            $path = "$name_str/" . ($upper ? 'Controller' : 'controller');
            $name = ucfirst(substr($name, $pos + 1));
            $file = app_path() . "/$path/$name.php";
            $namespace = str_replace('/', '\\', ($upper ? 'App/' : 'app/') . $path);
        }
        $this->createController($name, $namespace, $file, $service);

        return self::SUCCESS;
    }

    protected function pluralizeWord($word)
    {
        $irregulars = [
            'child' => 'children',
            'man'   => 'men',
            'woman' => 'women',
            'tooth' => 'teeth',
            'foot'  => 'feet',
            'mouse' => 'mice',
            // ...
        ];

        $exceptions = [
            'quiz' => 'quizzes',
            'box'  => 'boxes',
            // ...
        ];

        if (isset($irregulars[$word])) {
            return $irregulars[$word];
        }

        if (isset($exceptions[$word])) {
            return $exceptions[$word];
        }

        if (substr($word, -1) === 'y') {
            // 处理以 y 结尾的情况
            return substr($word, 0, -1) . 'ies';
        }

        if (substr($word, -2) === 'ss' || substr($word, -2) === 'sh' || substr($word, -2) === 'ch') {
            // 处理以 ss、sh 或 ch 结尾的情况
            return $word . 'es';
        }

        // 默认情况，在单词末尾加上 s
        return $word . 's';
    }

    /**
     * @param $name
     * @param $namespace
     * @param $file
     * @param null $service
     * @throws \Exception
     */
    protected function createController($name, $namespace, $file, $service = null)
    {
        $code = str_replace("Controller", "", $name);
        if (empty($service)) {
            $serviceClass = "{$code}Service";
        } else if (strpos($service, '/') === false) {
            $code = ucfirst($service);
            $serviceClass = "{$code}Service";
        } else {
            $codes = array_map(function ($code) {
                return ucfirst($code);
            }, explode('/', $service));
            $code = $codes[0];
            $serviceClass = "{$codes[1]}Service";
        }

        $serviceName = rtrim($serviceClass, "Service");
        $serviceFullClass = "CoreW\\Business\\{$code}\\Service\\Impl\\{$serviceClass}Impl";
        if (!class_exists($serviceFullClass)) {
            throw new \Exception("Please Use the command 'make:biz' to generate the service layer code or create the service layer code manually");
        }
        $path = pathinfo($file, PATHINFO_DIRNAME);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        $baseControllerNamespace = str_replace('controller', '', $namespace);
        $baseController = "BaseController";
        $baseControllerClass = $baseControllerNamespace . $baseController;
        if (class_exists($baseControllerClass)) {
            $useBaseControllerClassStr = "use $baseControllerClass;";
            $extendBaseControllerStr = " extends $baseController";
        } else {
            $useBaseControllerClassStr = "";
            $extendBaseControllerStr = "";
        }
        $filterNamespace = str_replace("controller", "filter", $namespace);
        //use app\admin\filters\AttachmentGroupFilter;
        $filterClass = sprintf("%sFilter", $code);
        $fullFilterClass = "{$filterNamespace}\\{$filterClass}";
        if (class_exists($fullFilterClass)) {
            $useFilterClassStr = "use $fullFilterClass;";
            $newFiltersClassStr = "\$filter = new \${$filterClass}();\n\t\t\t\t\$filter->filters(\$items);\n";
            $newFilterClassStr = "\$filter = new \${$filterClass}();\n\t\t\t\t\$filter->filter(\$item);\n";
        } else {
            $useFilterClassStr = "";
            $newFiltersClassStr = "";
            $newFilterClassStr = "";
        }

        $pluralizeName = $this->pluralizeWord($serviceName);
        $controller_content = <<<EOF
<?php

namespace $namespace;

use support\Request;
use support\utils\Paginator;
$useFilterClassStr
use CoreW\Business\\$code\Service\\$serviceClass;
$useBaseControllerClassStr

class $name$extendBaseControllerStr
{
    public function index(Request \$request)
    {
        \$conditions = [];
        \$fields = \$request->get();
        if (!empty(\$fields['keyword'])) {
            \$conditions['keyword'] = \$fields['keyword'];
        }

        \$total = \$this->get{$serviceClass}()->count{$serviceName}(\$conditions);
        list(\$offset, \$limit) = \$this->getOffsetAndLimit(\$request);
        \$sort = \$this->getSort(\$request);
        \$sort['id'] = 'DESC';
        \$paginator = new Paginator(\$offset, \$total, \$request->uri(), \$limit);
        \$items = \$this->get{$serviceClass}()->search{$pluralizeName}(\$conditions, \$sort, \$paginator->getOffsetCount(), \$paginator->getPerPageCount());
        $newFiltersClassStr
        return \$this->createSuccessJsonResponse([
            'list' => \$items,
            'paginator' => Paginator::toArray(\$paginator)
        ]);
    }
    
    public function store(Request \$request)
    {
        \$this->get{$serviceClass}()->create{$serviceName}(\$request->post());
        return \$this->createSuccessJsonResponse();
    }
    
    public function show(Request \$request, \$id)
    {
        \$item = \$this->get{$serviceClass}()->get{$serviceName}ById(\$id);
        $newFilterClassStr
        
        return \$this->createSuccessJsonResponse(\$item);
    }
    
    public function update(Request \$request, \$id)
    {
        \$this->get{$serviceClass}()->update{$serviceName}(\$id, \$request->post());
        
        return \$this->createSuccessJsonResponse();
    }
    
    public function destroy(Request \$request, \$id)
    {
        if (\$this->get{$serviceClass}()->delete{$serviceName}ById(\$id)) {
            return \$this->createSuccessJsonResponse();
        }
        
        return \$this->createErrorJsonResponse('删除失败');
    }
    
    
    /**
     * @return {$serviceClass}
     */
    protected function get{$serviceClass}()
    {
        return \$this->createService('{$code}:$serviceClass');
    }

}

EOF;
        file_put_contents($file, $controller_content);
    }
}