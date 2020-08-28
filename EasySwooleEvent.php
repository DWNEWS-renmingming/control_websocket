<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/5/28
 * Time: 下午6:33
 */

namespace EasySwoole\EasySwoole;

use App\WebSocket\WebSocketEvent;
use App\WebSocket\WebSocketParser;
use EasySwoole\EasySwoole\AbstractInterface\Event;
use EasySwoole\EasySwoole\Swoole\EventRegister;
use EasySwoole\Http\Request;
use EasySwoole\Http\Response;
use EasySwoole\Socket\Dispatcher;
use EasySwoole\Http\Message\Status;

class EasySwooleEvent implements Event
{
    /**
     * 框架初始化事件
     * 在Swoole没有启动之前 会先执行这里的代码
     */
    public static function initialize()
    {
        // TODO: Implement initialize() method.
        date_default_timezone_set('Asia/Shanghai');//设置时区
    }

    /**
     * mainServerCreate
     *
     * @param EventRegister $register
     * @throws \Exception
     */
    public static function mainServerCreate(EventRegister $register)
    {
        /**
         * **************** websocket控制器 **********************
         */
        // 创建一个 Dispatcher 配置
        $conf = new \EasySwoole\Socket\Config();
        // 设置 Dispatcher 为 WebSocket 模式
        $conf->setType(\EasySwoole\Socket\Config::WEB_SOCKET);
        // 设置解析器对象
        $conf->setParser(new WebSocketParser());
        // 创建 Dispatcher 对象 并注入 config 对象
        $dispatch = new Dispatcher($conf);
        // 给server 注册相关事件 在 WebSocket 模式下  on message 事件必须注册 并且交给 Dispatcher 对象处理
        $register->set(EventRegister::onMessage, function (\swoole_websocket_server $server, \swoole_websocket_frame $frame) use ($dispatch) {
            $dispatch->dispatch($server, $frame->data, $frame);
        });
        //自定义握手事件
        $websocketEvent = new WebSocketEvent();
        $register->set(EventRegister::onHandShake, function (\swoole_http_request $request, \swoole_http_response $response) use ($websocketEvent) {
            $websocketEvent->onHandShake($request, $response);
        });
        //自定义关闭事件
        $register->set(EventRegister::onClose, function (\swoole_server $server, int $fd, int $reactorId) use ($websocketEvent) {
            $websocketEvent->onClose($server, $fd, $reactorId);
        });
    }

    public static function onRequest(Request $request, Response $response): bool
    {
        /*$response->withHeader('Access-Control-Allow-Origin', '*');
		$response->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
		$response->withHeader('Access-Control-Allow-Credentials', 'true');
		$response->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
		if ($request->getMethod() === 'OPTIONS') {
			$response->withStatus(Status::CODE_OK);
			return false;
		}*/
        
		$_GET = $request->getQueryParams ();
		$_POST = $request->getRequestParam ();
		$_REQUEST = $request->getRequestParam ();
		$_COOKIE = $request->getCookieParams ();
		$_SERVER = $request->getServerParams ();
		// TODO: Implement onRequest() method.
		return true;
        return true;
    }

    public static function afterRequest(Request $request, Response $response): void
    {

    }

    public static function onReceive(\swoole_server $server, int $fd, int $reactor_id, string $data): void
    {

    }
}
