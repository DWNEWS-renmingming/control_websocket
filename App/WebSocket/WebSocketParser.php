<?php
/**
 * Created by PhpStorm.
 * User: Apple
 * Date: 2018/11/1 0001
 * Time: 14:41
 */

namespace App\WebSocket;


use EasySwoole\Socket\AbstractInterface\ParserInterface;
use EasySwoole\Socket\Client\WebSocket;
use EasySwoole\Socket\Bean\Caller;
use EasySwoole\Socket\Bean\Response;

/**
 * Class WebSocketParser
 *
 * 此类是自定义的 websocket 消息解析器
 * 此处使用的设计是使用 json string 作为消息格式
 * 当客户端消息到达服务端时，会调用 decode 方法进行消息解析
 * 会将 websocket 消息 转成具体的 Class -> Action 调用 并且将参数注入
 *
 * @package App\WebSocket
 */
class WebSocketParser implements ParserInterface
{
    /**
     * decode
     * @param  string         $raw    客户端原始消息
     * @param  WebSocket      $client WebSocket Client 对象
     * @return Caller         Socket  调用对象
     */
    public function decode($raw, $client) : ? Caller
    {
        // new 调用者对象
        $caller =  new Caller();
        if(!$raw) {
            $caller->setControllerClass("\\App\\WebSocket\\Controller\\Index");
            $caller->setAction('heartbeat1');
            return $caller;
        }
        $raw = trim($raw);
        $PING_INFO = ! empty ( $raw ) ? explode('_', $raw ) : []; 
        $PING      = $PING_INFO[0];
        if ($PING !== 'PING') {
            // 解析 客户端原始消息
            $payload = json_decode($raw, true);
            $class = isset($payload['controller']) ? $payload['controller'] : 'index';
            $action = isset($payload['action']) ? $payload['action'] : 99;
            $params = isset($payload['params']) ? (array)$payload['params'] : [];
            $controllerClass = "\\App\\WebSocket\\Controller\\" . ucfirst($class);
            if (!class_exists($controllerClass)) $controllerClass = "\\App\\WebSocket\\Controller\\Index";
            $caller->setClient($caller);
            $caller->setControllerClass($controllerClass);
            // 设置被调用的方法
            $action = self::action($action);
            $caller->setAction($action);
            // 设置被调用的Args
            $caller->setArgs($params);
        } else {
            $caller->setControllerClass("\\App\\WebSocket\\Controller\\Index");
            $caller->setAction('heartbeat');
            // 设置被调用的Args
            $caller->setArgs(
                [
                    'userID' => ! empty ( $PING_INFO[1] ) && is_numeric( $PING_INFO[1] ) ? $PING_INFO[1] : ''
                ]
            );
        }
        return $caller;
    }
    /**
     * 方法
     */
    public function action($action = '') {
        if(!$action) {
            return false;
        }
        $actionAll = [
            2 => 'IntoRoom',
            3 => 'OptionRoom',
            4 => 'exitRoom',
            5 => 'createMeeting',
            99 => 'heartbeat1',
        ];
        return ! empty ( $actionAll[$action] ) ? $actionAll[$action] : 'heartbeat1';
    }
    /**
     * encode
     * @param  Response     $response Socket Response 对象
     * @param  WebSocket    $client   WebSocket Client 对象
     * @return string             发送给客户端的消息
     */
    public function encode(Response $response, $client) : ? string
    {
        /**
         * 这里返回响应给客户端的信息
         * 这里应当只做统一的encode操作 具体的状态等应当由 Controller处理
         */
        return $response->getMessage();
    }
}
