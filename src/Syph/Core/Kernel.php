<?php
/**
 * Created by PhpStorm.
 * User: Bruno
 * Date: 19/08/2015
 * Time: 16:25
 */

namespace Syph\Core;

use Syph\Container\Container;
use Syph\Core\Interfaces\SyphKernelInterface;
use Syph\AppBuilder\Environment;
use Syph\AppBuilder\Interfaces\BuilderInterface;
use Syph\DependencyInjection\ServiceInterface;
use Syph\Http\Base\Request;
use Syph\Http\Http;
use Syph\Http\Interfaces\HttpInterface;
use Syph\Routing\Router;

abstract class Kernel implements SyphKernelInterface,ServiceInterface
{
    protected $apps = array();
    protected $isBooted;
    protected $env;
    protected $http;
    protected $builder;
    protected $container;
    protected $syphAppDir;

    const VERSION = '0.1';

    public function __construct(Environment $env,Request $request)
    {
        if(!$this->isBooted){
            $this->boot($env,$request);
        }

    }

    private function boot(Environment $env,Request $request)
    {
        $this->env = $env;
        $this->syphAppDir = $this->getSyphAppDir();

        $this->initApps();
        $this->initContainer($request);
        $this->bindContainerApps();
        $this->bindRouterRequest();

        $this->isBooted = true;
    }

    private function initApps()
    {
        foreach ($this->registerApps() as $app) {
            $name = $app->getName();
            if (isset($this->apps[$name])) {
                throw new \LogicException(sprintf('You trying to register two apps with the same name "%s"', $name));
            }
            $this->apps[$name] = $app;
        }

    }

    private function initContainer(Request $request)
    {
        $this->container = new Container($this);
        $this->container->set($request);
        $this->container->startContainer($this->getServiceList());
        $this->container->loadCustomContainer($this->getCustomList());

    }

    private function bindContainerApps(){
        foreach ($this->apps as $app) {
            $app->setContainer($this->container);
        }

    }

    private function bindRouterRequest(){
//        $this->container->set($request);
        $router = $this->container->get('routing.router');
        $request = $this->container->get('http.request');
        if($request->get->has('path')){
            $request->setAttributes($router->match($request->get->get('path')));
        }else{
            $request->setAttributes($router->match('/'));
        }

    }

    private function getServiceList(){
        $list = require_once 'Config/services.php';
        return $list['services'];
    }

    private function getCustomList(){
        $pathCustomServices = $this->syphAppDir.'/../global/services.php';
        if(file_exists($pathCustomServices)) {
            $list = require_once $pathCustomServices;
            return $list['services'];
        }
        return array();
    }

    public function getSyphAppDir(){
        if (null === $this->syphAppDir) {
            $r = new \ReflectionObject($this);
            $this->syphAppDir = str_replace('\\', '/', dirname($r->getFileName()));
        }

        return $this->syphAppDir;
    }

    public function handleRequest(BuilderInterface $builder)
    {

        $this->builder = $builder;
        if($this->isBooted) {
            $builder->register($this->env);
        }

        return $this->getHttp()->run($this->container->get('http.request'));
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

    public function getResponse()
    {
//        $route = Router::execute($this->http->getRequest());
//
//        if(isset($route['args'])){
//            return $this->handleController($route['controller'],$route['action'],$route['args']);
//        }else{
//            return $this->handleController($route['controller'],$route['action']);
//        }

    }

    public function handleController($controllerName,$actionName,$args = array())
    {

        $controllerArr = explode(':',$controllerName);
        $appName = $controllerArr[0];
        if($this->builder->hasApp($appName)) {
            $controllerName = $controllerArr[1];

            $controller_path = APP_REAL_PATH . DS . 'app' . DS . $appName . DS . 'Controller' . DS . $controllerName . '.php';

            $this->app = $this->builder->loadApp($appName);

            if (file_exists($controller_path)) {
                include_once($controller_path);

                $controller = '\\' . $appName . '\\' . 'Controller' . '\\' . $controllerName;

                if (method_exists($controller, $actionName)) {

                    return $this->runController($controller, $actionName, $args);
                } else {
                    throw new \Exception(sprintf('M�todo: %s n�o encontrado', $actionName), 404);
                }

            } else {
                throw new \Exception(sprintf('Controlador: %s n�o encontrado', $controllerName), 404);
            }
        }else{
            throw new \Exception(sprintf('Applica��o: %s n�o existe', $appName), 404);
        }
    }

    public function runController($controllerName,$action,$args = array())
    {
        if(is_callable(array($controllerName ,$action)))
        {
            return call_user_func_array(array( new $controllerName ,$action), $args);
        }
        throw new \Exception('Controller n�o pode ser chamado',404);
    }

    public function getApp($appName){
        return array($this->apps[$appName]);
    }

    public function getName(){
        return 'kernel';
    }

}
