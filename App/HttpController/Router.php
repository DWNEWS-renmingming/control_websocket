<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/8/15
 * Time: 上午10:39
 */

namespace App\HttpController;


use EasySwoole\Http\AbstractInterface\AbstractRouter;
use FastRoute\RouteCollector;
use EasySwoole\Http\Request;
use EasySwoole\Http\Response;

class Router extends AbstractRouter
{

    /**
     * Define membership interface information
     *
     * @author frank
     * {@inheritdoc}
     *
     * @see \EasySwoole\Http\AbstractInterface\AbstractRouter::initialize()
     */
    function initialize(RouteCollector $routeCollector) {
        $this->setGlobalMode(true);
        $this->setMethodNotAllowCallBack(function (Request $request, Response $response) {
            $response->write("HTTP/1.1 403 Forbidden");
            return false;
        });
        $this->setRouterNotFoundCallBack(function (Request $request, Response $response) {
            // $response->write("HTTP/1.1 405 Forbidden");
            $response->redirect("https://etest.eovobochina.com/");
            return false;
        });

        $routeCollector->get('/v3','WebSocket/index');
        $routeCollector->get('/v3/broadcast','WebSocket/broadcast');
        $routeCollector->get('/v3/push','WebSocket/push');
      
       
        //健康检测
        $routeCollector->get('/healthz', '/Healthz/index');
    }
}