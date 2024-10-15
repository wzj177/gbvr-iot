<?php


namespace CoreW\Mail\Template;


use CoreW\Business\Setting\Service\LogService;
use CoreW\Business\Setting\Service\SettingService;
use CoreW\Context\BfwAware;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class BaseTemplate
{
    use BfwAware;

    protected function renderBody($view, $params = [])
    {
        $loader = new FilesystemLoader($this->bfw['email_template_paths']);
        $twig = new Environment($loader);

        return $twig->render($view, $params);
    }

    protected function getSiteName()
    {
        $config = $this->getSettingService()->get('basic', []);

        return $config['site_name'] ?? config('app.name');
    }


    /**
     * @return SettingService
     */
    protected function getSettingService()
    {
        return $this->bfw->service('Setting:SettingService');
    }
}