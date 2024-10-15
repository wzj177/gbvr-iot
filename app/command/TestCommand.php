<?php

namespace app\command;

use CoreW\Bfw;
use CoreW\Core;
use support\utils\AssetHelper;
use support\utils\FileToolkit;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;


class TestCommand extends Command
{
    protected static $defaultName = 'hi:webman';
    protected static $defaultDescription = '测试';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'Name description');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        //http://localhost:3010/share/RVhwQ2VkY1R3M1lHL3ZxRUVyNGpBdz09
        $this->getBiz()->service('Product:ProductService')->checkShareToken("RVhwQ2VkY1R3M1lHL3ZxRUVyNGpBdz09");
//        $name = $input->getArgument('name');
//        $output->writeln('Hello TestCommand');
//        echo FileToolkit::formatFileSize(71563679), ',', FileToolkit::formatFileSize(1073741824), PHP_EOL;
//        var_dump(AssetHelper::absoluteUrl('/api/v1/auth/index'));
//        $output->writeln($this->getBiz()->offsetGet('debug') ? '1' : '2');
//        echo $this->wave([1], ['remainedTimes' => -1]), PHP_EOL;

        return self::SUCCESS;
    }

    protected function wave(array $ids, array $diffs)
    {
        $sets = array_map(
            function ($name) {
                return "{$name} = {$name} + ?";
            },
            array_keys($diffs)
        );

        $marks = str_repeat('?,', count($ids) - 1).'?';

        $sql = "UPDATE user_tokens SET ".implode(', ', $sets)." WHERE id IN ($marks)";

        return $sql;
    }

    /**
     * @return Bfw
     */
    protected function getBiz()
    {
        return Core::initCiBiz();
    }
}
