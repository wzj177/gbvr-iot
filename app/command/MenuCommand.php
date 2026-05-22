<?php

namespace app\command;

use CoreW\Business\Menu\Service\MenuService;
use CoreW\Core;
use CoreW\Traits\UserSessionTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MenuCommand extends Command
{
    use UserSessionTrait;

    protected static $defaultName = 'menu:init';
    protected static $defaultDescription = '初始化菜单数据';

    protected function configure()
    {
        // 可以添加参数或选项
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<info>开始初始化菜单数据</info>');

        try {
            $menuFile = base_path('migrations/seeders/default-menu.json');

            if (!file_exists($menuFile)) {
                $output->writeln("<error>菜单文件不存在: {$menuFile}</error>");
                return self::FAILURE;
            }

            $jsonContent = file_get_contents($menuFile);
            $menuData = json_decode($jsonContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $output->writeln("<error>菜单文件格式错误: " . json_last_error_msg() . "</error>");
                return self::FAILURE;
            }

            if (!isset($menuData['menus']) || !is_array($menuData['menus'])) {
                $output->writeln("<error>菜单文件格式错误: 缺少 menus 字段</error>");
                return self::FAILURE;
            }

            $this->getMenuService()->syncMenusFromJson($menuData);

            $output->writeln('<info>菜单数据初始化完成</info>');
            return self::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln("<error>初始化失败: {$e->getMessage()}</error>");
            return self::FAILURE;
        }
    }

    /**
     * @return MenuService
     */
    protected function getMenuService() : MenuService
    {
        return $this->getBiz()->service('Menu:MenuService');
    }

    /**
     * @return \CoreW\Bfw
     */
    protected function getBiz()
    {
        return Core::initCiBiz();
    }
}
