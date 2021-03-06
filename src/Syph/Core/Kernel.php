<?php
/**
 * Created by PhpStorm.
 * User: Bruno
 * Date: 19/08/2015
 * Time: 16:25
 */

namespace Syph\Core;

use Syph\AppBuilder\AppBuilder;
use Syph\Autoload\ClassLoader;
use Syph\Console\ConsoleApp;
use Syph\Core\Events\KernelBootEvent;
use Syph\Core\Events\KernelEventList;
use Syph\Core\Events\RequestStartEvent;
use Syph\DependencyInjection\Container\Container;
use Syph\Core\Interfaces\SyphKernelInterface;
use Syph\AppBuilder\Interfaces\BuilderInterface;
use Syph\DependencyInjection\Container\OmniContainer;
use Syph\DependencyInjection\ServiceInterface;
use Syph\EventDispatcher\EventDispatcher;
use Syph\Exception\ExceptionHandler;
use Syph\Http\Base\Request;
use Syph\Http\Http;
use Syph\Routing\Router;

abstract class Kernel implements SyphKernelInterface,ServiceInterface
{
    protected $apps = array();
    protected $isBooted;
    protected $env;
    protected $start;
    protected $execturion_time;
    protected $mode;
    protected $http;
    protected $builder;
    protected $accept_configs = [];
    protected $global_configs = [];
    /**
     * @var Container $container
     */
    protected $container;
    /**
     * @var EventDispatcher $dispatcher
     */
    protected $dispatcher;
    protected $syphAppDir;
    protected $syphAppLoggDir;
    /**
     * @var ParameterCollection $parameters
     */
    protected $parameters;
    /**
     * @var ConfigProvider $configProvider
     */
    private $configProvider;

    const VERSION = '1.2';

    public function __construct(Request $request = null)
    {
        $this->start = microtime(true);
        if(null === $request){
            $this->mode = 'CLI';
            $request = Request::create($this->mode);
        }

        if(!$this->isBooted){
            $this->boot($request);
        }

    }

    private function boot(Request $request)
    {
//        $this->env = $env;

        try{
            $this->syphAppDir = $this->getSyphAppDir();

            $this->initClassLoader();
            $this->loadGlobalConfigs();
            $this->initApps();
            $this->initContainer($request);
            $this->initFunctions();
            $this->initEventDispatcher();
            $this->bindContainerApps();


            $this->isBooted = true;
        }catch (\Exception $e){
            throw $e;
        }
        if (!$this->mode == 'CLI'){
            $this->dispatcher->dispatch(
                KernelEventList::KERNEL_BOOTED,
                new KernelBootEvent(
                    $this->container,
                    $this->configProvider
                )
            );

            $this->bindRouterRequest();
        }
    }

    private function initEventDispatcher()
    {
        $this->dispatcher = $this->container->get('event.dispatcher');
        $this->dispatcher->loadContainerListeners();
    }

    private function initFunctions()
    {
        include_once(realpath(dirname(__FILE__)).'/../Helpers/functions.php');
    }

    private function initClassLoader()
    {
        $loader = new ClassLoader();

        $loader->register();

        foreach (new \DirectoryIterator(USER_APPS_DIR) as $fileInfo) {
            if($fileInfo->isDot()) continue;
            if($fileInfo->isFile()) continue;
            $loader->addNamespace($fileInfo->getFilename(), USER_APPS_DIR.DS.$fileInfo->getFilename());
        }

    }

    private function initApps()
    {
        foreach ($this->registerApps() as $app) {
            $name = $app->getName();
            if (isset($this->apps[$name])) {
                throw new \LogicException(sprintf('You trying to register two apps with the same name "%s"', $name));
            }
            $app->buildConfig($this->configProvider);

            $this->apps[$name] = $app;
        }

    }

    private function initContainer(Request $request)
    {

        $this->container = new Container($this);
        $this->container->set($request);
        if (!$this->mode == 'CLI'){
            $this->container->startContainer($this->global_configs['services']);
        }else{
            $this->container->startContainer($this->global_configs['services_cli']);
        }
        $omniContainer = OmniContainer::getInstance();
        $omniContainer->setContainer($this->container);

    }

    public function getNativeCommands(){
        $files = [];
        foreach (new \DirectoryIterator(ConsoleApp::CONSOLE_DIR.DS."Commands".DS."NativeCommands") as $fileInfo) {
            if($fileInfo->isDot()) continue;
            if($fileInfo->isDir()) continue;
            if(preg_match('/Command\.php/',$fileInfo->getFilename()))
                $files[] = $fileInfo->getBasename('.php');
        }
        return $files;
    }

    private function bindContainerApps(){
        foreach ($this->apps as $app) {
            $app->setContainer($this->container);
        }

    }

    private function bindRouterRequest(){
//        $this->container->set($request);
        /**
         * @var Router $router
         */
        $router = $this->container->get('routing.router');

        /**
         * @var Request $request
         */
        $request = $this->container->get('http.request');
        if($request->get->has('path')){
            $request->setAttributes($router->match($request->method,$request->get->get('path')));
        }else{
            $request->setAttributes($router->match('GET','/'));
        }

    }
    private function loadGlobalConfigs()
    {

        $configProvider = new ConfigProvider();
        $configProvider->build();
        $this->configProvider = $configProvider;
        $this->global_configs = $configProvider->getConfig();
        $this->parameters = $configProvider->getParameters();
    }

    public function getSyphAppDir(){
        if (null === $this->syphAppDir) {
            $r = new \ReflectionObject($this);
            $this->syphAppDir = str_replace('\\', '/', dirname($r->getFileName()));
        }

        return $this->syphAppDir;
    }

    public function getSyphAppLoggDir()
    {
        if (null === $this->syphAppLoggDir) {
            $p = $this->getSyphAppDir();
            $this->syphAppLoggDir = $p.DS.'..'.DS.'storage'.DS.'logs';
        }
        return $this->syphAppLoggDir;
    }

    public function handleRequest(BuilderInterface $builder = null)
    {

        if($this->isBooted) {
            //$this->loadBuilder($builder)->register($this->env);
        }
        try {
            $this->dispatcher->dispatch(KernelEventList::REQUEST_HANDLE, new RequestStartEvent(
                $this->container->get('http.request'),$this->container->get('routing.router')
            ));
            $response = $this->getHttp()->run($this->container->get('http.request'));

            return $response;
        }catch (\Exception $e){
            return $this->handleException($e);
        }
    }

    private function handleException(\Exception $e)
    {
        $handler = new ExceptionHandler();
        $handler->buildResponse($e);
        return $handler->getResponse();
    }

    private function loadBuilder($builder){
        if(!is_null($builder) && $builder instanceof BuilderInterface){
            return $builder;
        }
        return new AppBuilder();
    }

    /**
     * Gets a HTTP from the container.
     *
     * @return Http
     */
    protected function getHttp()
    {
        return $this->container->get('http.core');
    }


    public function getApps(){
        return $this->apps;
    }

    public function getApp($appName){
        return array($this->apps[$appName]);
    }

    public function getName(){
        return 'kernel';
    }

    public function getConfigProvider()
    {
        return $this->configProvider;
    }

    public function getConfig($name = null)
    {
        if(null === $name)
        {
            return $this->global_configs;
        }

        if (array_key_exists($name,$this->global_configs))
        {
            return $this->global_configs[$name];
        }
        return null;
    }

    private function setEnv($param)
    {
        $this->env = $param;
    }

    public function getEnv()
    {
        return $this->env;
    }

    public function getParameter($parameter_name)
    {
        return $this->parameters->get($parameter_name);
    }

    public function finish()
    {
        $this->execturion_time = microtime(true) - $this->start;
    }

}
