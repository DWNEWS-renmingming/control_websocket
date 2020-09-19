<?php
/**
 * Created by PhpStorm.
 * User: evalor
 * Date: 2018-11-28
 * Time: 20:23
 */

namespace App\Task;


use EasySwoole\EasySwoole\ServerManager;
use App\WebSocket\WebSocketAction;
use EasySwoole\Task\AbstractInterface\TaskInterface;
use App\Utility\Pool\RedisPool;

/**
 * 发送广播消息
 * Class BroadcastTask
 * @package App\Task
 */
class BroadcastTask implements TaskInterface
{
    protected $taskData;

    public function __construct($taskData)
    {
        $this->taskData = $taskData;
    }


    /**
     * 执行投递
     * @param $taskData
     * @param $taskId
     * @param $fromWorkerId
     * @param $flags
     * @return bool
     */
     function run(int $taskId, int $workerIndex)
    {
        try {
            sleep(40);
            $taskData = $this->taskData;
            $taskDataInf0   = $taskData['payload'];
            $userID         = $taskDataInf0['userID'];
            $roomID         = $taskDataInf0['roomID'];
            $toID           = $taskDataInf0['toID'];
            $toFD           = $taskDataInf0['toFD'];
            $userFd         = $taskData['fromFd'];

            if($userID && $toID && $userFd && $toFD && $roomID) {

                $redis =  RedisPool::defer();

                $success_invitation = WebSocketAction::ver_get_success_invitation;//成功邀请的列表
                //检测toID是否在永久桶里面  我的成功邀请的列表
                $success_invitation = $success_invitation . $roomID;
                $zrank =  $redis->zrank($success_invitation, $toID );//我的成功邀请列表
                if( $zrank === 0 || $zrank) {
                    $flag = true;
                } else {
                    $flag = false;
                }
                //我的成果邀请列表存在 toID
                if(  $flag  == false) {  
                    // '主动触发,关闭页面,长时间未响应,用户不在线';
                    $temporary_pain = WebSocketAction::ver_get_temporary_pain;//零时
                    $redis->zrem($temporary_pain, $userID);
                    $redis->zrem($temporary_pain, $toID);
                    /** @var \swoole_websocket_server $server */
                    $server = ServerManager::getInstance()->getSwooleServer();
                    $redis_name_userFd =  WebSocketAction::ver_get_room_language . $userFd;
                    $lang_userFD       = $redis->get($redis_name_userFd);
                    $connection = $server->connection_info($userFd);
                    if ($connection['websocket_status'] == 3) {  // 用户正常在线时可以进行消息推送
                        $sendData = [
                            'action' => WebSocketAction::msg_1005,//长时间未响应,用户不在线
                            'data'   =>  [
                                'code'    => 200, 
                                'message'  => $lang_userFD == 'en' ? 'No response for a long time, the user is not online' : '长时间未响应,用户不在线',
                                'status'   => WebSocketAction::msg_1005 //长时间未响应,用户不在线 隐藏div
                            ]
                        ];
                        $server->push( $userFd, json_encode($sendData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) );
                    }
                    //对方·超时间未操作 主动关闭
                    $redis_name_toFD =  WebSocketAction::ver_get_room_language . $toFD;
                    $lang_toFD       = $redis->get($redis_name_toFD);
                    $connection1 = $server->connection_info($toFD);
                    if ($connection1['websocket_status'] == 3) {  // 用户正常在线时可以进行消息推送
                        $sendData1 = [
                            'action' => WebSocketAction::msg_1013,//长时间未响应,用户不在线
                            'data'   =>  [
                                'code'    => 200, 
                                'message'  =>  $lang_toFD == 'en' ? 'Timeout no operation, automatic exit' :'超时未操作,自动退出',
                                'status'   => WebSocketAction::msg_1013 //超时未操作,自动退出
                            ]
                        ];
                        $server->push( $toFD, json_encode($sendData1, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) );
                    }
                }
            }
            return true;
        } catch (\Exception $e) {
            return true;
        }
    }

    function onException(\Throwable $throwable, int $taskId, int $workerIndex)
    {
        throw $throwable;
    }

}