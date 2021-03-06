<?php
/**
 * Created by PhpStorm.
 * User: Bruno Louvem
 * Date: 27/09/2015
 * Time: 10:56
 */

namespace Syph\AppBuilder\Interfaces;


use Syph\Core\ConfigProvider;

interface AppInterface {
    public function buildConfig(ConfigProvider $configProvider);
    public function getName();
    public function getNamespace();
    public function getPath();
    public function getDbStrategy();
    public function getDefaultTemplateEngine();
}