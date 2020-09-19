<?php
/**
 * Created by PhpStorm.
 * User: Apple
 * Date: 2018/11/1 0001
 * Time: 14:47
 */

namespace App\WebSocket;
use App\Utility\Pool\RedisPool;
use App\WebSocket\WebSocketAction;
/**
 * Class WebSocketEvent
 *
 * 此类是 WebSocet 中一些非强制的自定义事件处理
 *
 * @package App\WebSocket
 */
class WebSocketEvent
{ 
    /**
     * 握手事件
     *
     * @param \swoole_http_request  $request
     * @param \swoole_http_response $response
     * @return bool
     */
    public function onHandShake(\swoole_http_request $request, \swoole_http_response $response)
    {
        /** 此处自定义握手规则 返回 false 时中止握手 */
        if (!$this->customHandShake($request, $response)) {
            $response->end();
            return false;
        }

        /** 此处是  RFC规范中的WebSocket握手验证过程 必须执行 否则无法正确握手 */
        if ($this->secWebsocketAccept($request, $response)) {
            $response->end();
            return true;
        }

        $response->end();
        return false;
    }

    /**
     * 自定义握手事件
     *
     * @param \swoole_http_request  $request
     * @param \swoole_http_response $response
     * @return bool
     */
    protected function customHandShake(\swoole_http_request $request, \swoole_http_response $response): bool
    {
        /**
         * 这里可以通过 http request 获取到相应的数据
         * 进行自定义验证后即可
         * (注) 浏览器中 JavaScript 并不支持自定义握手请求头 只能选择别的方式 如get参数
         */
        // $cookie = $request->cookie;
        $headers = $request->header;
        print_r($request);
        $fd      =  $request->fd;
        $lang    =  ! empty ( $headers['lang'] ) ? trim( $headers['lang'] ) : 'en';
        $redis_name   = WebSocketAction::ver_get_room_language . $fd;
        $redis   = RedisPool::defer();
        $lang    = $redis->set($redis_name, $lang);
        return true;
    }

    /**
     * 关闭事件
     *
     * @param \swoole_server $server  Server对象
     * @param int            $fd      连接的文件描述符
     * @param int            $reactorId   来自那个reactor线程，主动close关闭时为负数
     */
    public function onClose(\swoole_server $server, int $fd, int $reactorId)
    {
        //刷新删除 fd  TCP客户端连接关闭后 
        $redis =  RedisPool::defer();
        $redis_name_lang  = WebSocketAction::ver_get_room_language . $fd;
        $redis->del($redis_name_lang);
        $redis_name_fd   = WebSocketAction::ver_get_web_socket_fd . $fd;
        if ( $redis->exists( $redis_name_fd )  ) {
            $userID          =  $redis->get($redis_name_fd);
            $redis_name_user = WebSocketAction::ver_get_web_socket_user . $userID;
            $redis->del($redis_name_fd);
            $redis->del($redis_name_user);
            
            $temporary_pain = WebSocketAction::ver_get_temporary_pain;//零时
            $permanent_pain = WebSocketAction::ver_get_permanent_pain;//永久
            //删除永久桶记录人
            $redis->zrem($permanent_pain, $userID); 
            //删除零时桶记录人
            $redis->zrem($temporary_pain, $userID); 

            ########################会议管理 意外退出 销毁房间 推送消息 开始##################################
            $redis_name_user_room  = WebSocketAction::ver_get_room_management . 'user_' . $userID;
            if ( $redis->exists( $redis_name_user_room ) ) {
                $roomID          =  $redis->get($redis_name_user_room);
                //检测房间的key
                $redis_name_1   = WebSocketAction::ver_get_room_management . $roomID;
                $redis->del($redis_name_1);

                //房间内的人源的用户ID
                $redis_name_meeting_room      = WebSocketAction::ver_get_room_management_info . $roomID;
                //删除房间主人成功邀请列表
                $redis->del($redis_name_meeting_room);

                //成功邀请的列表 FD
                $ver_get_room_management_fd   = WebSocketAction::ver_get_room_management_fd . $roomID;
                //成功邀请的列表 FD
                if ( $redis->exists( $ver_get_room_management_fd ) ) {
                    $sendData    = [
                        'action' => WebSocketAction::msg_1017, //房主退出,推送房内人员的各个FD
                        'data'   => [
                            'code'    =>  WebSocketAction::SUCCESS_CODE, 
                            'message'  => '发起邀请人销毁房间,房间不存在',
                            'status'   => WebSocketAction::msg_1017 //房主退出
                        ]
                    ];
                    //成功邀请的列表 FD
                    $toFd_redis_name_info = $redis->zrevrange($ver_get_room_management_fd, 0, -1);
                    for ($i=0; $i < count($toFd_redis_name_info); $i++) { 
                        //fd推送
                        $toFd_redis_name_info_FD = $toFd_redis_name_info[$i];
                        $info = $server->getClientInfo($toFd_redis_name_info_FD);
                        if ($info && $info['websocket_status'] == WEBSOCKET_STATUS_FRAME) {
                            $server->push($toFd_redis_name_info_FD, json_encode( $sendData, JSON_UNESCAPED_UNICODE ));
                        } 
                    }
                    //删除房间主人成功邀请
                    $redis->del($ver_get_room_management_fd);
                }

                $redis->del($redis_name_user_room);
            }
            ########################会议管理 意外退出 销毁房间 推送消息 结束 ##################################

            ########################直播控制 意外退出  推送消息 开始 ##################################
            $success_invitation = WebSocketAction::ver_get_success_invitation;//成功邀请的列表 toID
            $success_invitation = $success_invitation . $userID;
            //成功邀请的列表 toID
            if ( $redis->exists( $success_invitation ) ) {
                $success_invitation_info = $redis->zrevrange($success_invitation, 0, -1);
                for ($i=0; $i < count($success_invitation_info); $i++) { 

                    $success_invitation_userID = $success_invitation_info[$i];
                    //删除永久桶记录人
                    $redis->zrem($permanent_pain, $success_invitation_userID); 
                    //删除零时桶记录人
                    $redis->zrem($temporary_pain, $success_invitation_userID); 
                }
                //删除房间主人成功邀请
                $redis->del($success_invitation);
            }

            $toFd_redis_name   = WebSocketAction::ver_get_success_invitation_fd . $userID;//成功邀请的列表 FD
            //成功邀请的列表 FD
            if ( $redis->exists( $toFd_redis_name ) ) {
                $sendData1    = [
                    'action' => WebSocketAction::msg_1011, //房主退出,
                    'data'   => [
                        'code'    =>  WebSocketAction::SUCCESS_CODE, 
                        'message'  => '房主退出,房间不存在',
                        'status'   => WebSocketAction::msg_1011 //房主退出
                    ]
                ];
                //成功邀请的列表 FD
                $toFd_redis_name_info1 = $redis->zrevrange($toFd_redis_name, 0, -1);
                for ($i=0; $i < count($toFd_redis_name_info1); $i++) { 
                    //fd推送
                    $toFd_redis_name_info_FD1 = $toFd_redis_name_info1[$i];
                    $info = $server->getClientInfo($toFd_redis_name_info_FD1);
                    if ($info && $info['websocket_status'] == WEBSOCKET_STATUS_FRAME) {
                        $server->push($toFd_redis_name_info_FD1, json_encode( $sendData1, JSON_UNESCAPED_UNICODE ));
                    } 
                }
                //删除房间主人成功邀请
                $redis->del($toFd_redis_name);
            }
            $redis->del('room_rid_'. $userID);
            $redis->del('room_fd_'. $fd);
            ########################会议管理 意外退出 销毁房间 推送消息 结束##################################
        } else {
            $redis->del($redis_name_fd);
        }    
        /** @var array $info */
        $info = $server->getClientInfo($fd);
        /**
         * 判断此fd 是否是一个有效的 websocket 连接
         * 参见 https://wiki.swoole.com/wiki/page/490.html
         */
        if ($info && $info['websocket_status'] === WEBSOCKET_STATUS_FRAME) {
            /**
             * 判断连接是否是 server 主动关闭
             * 参见 https://wiki.swoole.com/wiki/page/p-event/onClose.html
             */
            if ($reactorId < 0) {
                echo "server close \n";
            }
        }
    }

    /**
     * RFC规范中的WebSocket握手验证过程
     * 以下内容必须强制使用
     *
     * @param \swoole_http_request  $request
     * @param \swoole_http_response $response
     * @return bool
     */
    protected function secWebsocketAccept(\swoole_http_request $request, \swoole_http_response $response): bool
    {
        // ws rfc 规范中约定的验证过程
        if (!isset($request->header['sec-websocket-key'])) {
            // 需要 Sec-WebSocket-Key 如果没有拒绝握手
            // var_dump('shake fai1 3');
            return false;
        }
        if (0 === preg_match('#^[+/0-9A-Za-z]{21}[AQgw]==$#', $request->header['sec-websocket-key'])
            || 16 !== strlen(base64_decode($request->header['sec-websocket-key']))
        ) {
            //不接受握手
            // var_dump('shake fai1 4');
            return false;
        }

        $key = base64_encode(sha1($request->header['sec-websocket-key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $headers = array(
            'Upgrade'               => 'websocket',
            'Connection'            => 'Upgrade',
            'Sec-WebSocket-Accept'  => $key,
            'Sec-WebSocket-Version' => '13',
            'KeepAlive'             => 'off',
        );

        if (isset($request->header['sec-websocket-protocol'])) {
            $headers['Sec-WebSocket-Protocol'] = $request->header['sec-websocket-protocol'];
        }

        // 发送验证后的header
        foreach ($headers as $key => $val) {
            $response->header($key, $val);
        }

        // 接受握手 还需要101状态码以切换状态
        $response->status(101);
        //var_dump('shake success at fd :' . $request->fd);
        return true;
    }
}
