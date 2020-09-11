<?php
/**
 * Created by PhpStorm.
 * User: Apple
 * Date: 2018/11/1 0001
 * Time: 14:42
 */
namespace App\WebSocket\Controller;

use App\WebSocket\WebSocketAction;
use EasySwoole\Socket\AbstractInterface\Controller;
use EasySwoole\EasySwoole\ServerManager;
use EasySwoole\EasySwoole\Task\TaskManager;
use App\Task\BroadcastTask;

use App\Utility\Pool\MysqlPool;
use App\Utility\Pool\RedisPool;

use App\Model\Users\UsersModel;

/**
 * Class Index
 *
 * 此类是默认的 websocket 消息解析后访问的 控制器
 *
 * @package App\WebSocket
 */
class Index extends Controller
{
    public $redis;

    public function __construct() 
    {
        //redis配置
        $this->redis = RedisPool::defer();
        //继承父类的的 __construct
        parent::__construct();
    }
    
    public $operation_method = [ 
        'add',   
        'agree',
        'cancel',
        'delete',
    ];

    /**
     * 延时发送队列
     */
    function delay($fd = '',$roomID = '',$userID = '', $toID = '', $toFD = '')
    {
        TaskManager::getInstance()->async(new BroadcastTask(['payload' => ['roomID' => $roomID , 'userID' => $userID ,'toID' => $toID, 'toFD' => $toFD ], 'fromFd' => $fd]));
    }

     /**
     * 发起邀请和主动挂断
     * @throws \Exception
     */
    function IntoRoom()
    {
        /** @var WebSocketClient $client */
        $getFd = $this->caller()->getClient()->getFd();
        $broadcastPayload = $this->caller()->getArgs();
        if ( !empty($broadcastPayload) ) {
            $userID    = $broadcastPayload['userID'];
            $toID      = $broadcastPayload['toID'];
            $roomID    = ! empty ( $broadcastPayload['roomID'] ) ? intval( $broadcastPayload['roomID'] ) : '';
            $operation = $broadcastPayload['operation'];
            $operation_method = $this->operation_method;
            //房间ID
            if(! $roomID) {
                $roomID = $userID;
            }

            //判断邀请的用户是否在线
            $to_web_socket_user =  WebSocketAction::ver_get_web_socket_user . $toID;

            if ( ! $this->redis->exists( $to_web_socket_user )  ) {
               
                $result['code']     = WebSocketAction::SUCCESS_CODE;
                $result['message']  = '对方不在线';
                $result['status']   =  WebSocketAction::msg_1002;
                return  self::setMessage( $result, WebSocketAction::msg_1002 );
            } else {
                $toFD          =  $this->redis->get($to_web_socket_user);
            }

            if($userID == $toID) {
                $result['code']     = WebSocketAction::ERROR_CODE;
                $result['message']  = '自己不能邀请自己';
                return  self::setMessage( $result );
            }

            if( empty($operation) || !in_array($operation, $operation_method) )
            {
                $result['code']     = WebSocketAction::ERROR_CODE;
                $result['message']  = '操作型不能为空';
    
                return  self::setMessage( $result );
            }
            if(empty($userID) || !is_numeric($userID) || empty($toID) || !is_numeric($toID))
            {
                $result['code']     = WebSocketAction::ERROR_CODE;
                $result['message']  = '邀请人ID不能为空';
    
                return  self::setMessage( $result );
            }

            if($operation == 'add') {
                $this->redis->set('room_rid_'. $roomID, $roomID);
                $this->redis->set('room_fd_'. $getFd, $getFd);
                self::redis_expire_time('room_rid_'. $roomID, 7200);
                self::redis_expire_time('room_rid_'. $getFd, 7200);
            }
           
            $message = [
                'add'     => '发起邀请成功',
                'agree'   => '通过成功',
                'cancel'  => '拒绝成功',
                'delete'  => '我方主动挂断',
            ];

            $temporary_pain = WebSocketAction::ver_get_temporary_pain;//零时
            $permanent_pain = WebSocketAction::ver_get_permanent_pain;//永久
            $push_user      = WebSocketAction::ver_get_push_user_info;//推送

            $success_invitation = WebSocketAction::ver_get_success_invitation;//成功邀请的列表

            //邀请方主动触发删除
            if($operation == 'delete') {
                $this->redis->zrem($temporary_pain, $userID);
                $this->redis->zrem($temporary_pain, $toID);
                //删除推人
                $redis_name_push = $push_user . $toID;//推送人员   value fd
                $redis_value_1 =  $this->redis->get($redis_name_push);//删除推人 推送 list 列表
                $redis_value_1 = json_decode($redis_value_1, true);
                $redis_value_1_uid = $redis_value_1['uid'];
                if($redis_value_1_uid == $userID) {
                    $this->redis->del($redis_name_push);//删除推人
                }
                //推送人
                $sendData   = [
                    'action' => WebSocketAction::msg_1012,
                    'data'   => [
                        'code'     => WebSocketAction::SUCCESS_CODE, 
                        'message'  => '对方主动挂断',
                        'status'   => WebSocketAction::msg_1012 //主动挂断成功
                    ]
                ];

                self::push($toFD, $sendData);

                $result    = [
                    'code'    => WebSocketAction::SUCCESS_CODE, 
                    'message'  =>  $message[$operation],
                    'status'   => WebSocketAction::msg_1003 //主动挂断成功
                ];
                return  self::setMessage( $result, WebSocketAction::msg_1003 );
            }

            //检测toID是否在永久桶里面  我的成功邀请的列表
            $success_invitation = $success_invitation . $userID;
            $zrank_success_invitation =  $this->redis->zrank($success_invitation, $toID );//我的成功邀请列表
            //我的成果邀请列表存在 toID
            if( $zrank_success_invitation === 0 || $zrank_success_invitation) {  
                $result    = [
                    'code'    => WebSocketAction::SUCCESS_CODE, 
                    'userID'  =>  $userID,
                    'roomID'  =>  $roomID,
                    'message'  =>  '你们已经互通直播了',
                    'status'   => WebSocketAction::msg_1004 //你们已经互通直播了
                ];
                return  self::setMessage( $result, WebSocketAction::msg_1004 );
            }

            //检测该用户是否忙碌 和 我是否邀请这位用户
            $score     = time() + 30; 
            $now_time  = time(); 
            $zrank1_tid =  $this->redis->zrank($permanent_pain, $toID );//永久桶
            $zrank_tid  =  $this->redis->zrank($temporary_pain, $toID );//零时桶

            $zrank_uid =  $this->redis->zrank($temporary_pain, $userID );//零时桶

            //我存在 算时间
            if( $zrank_uid === 0 || $zrank_uid) {    
                // 算时间;
                $zscore_uid =  $this->redis->zscore($temporary_pain, $userID);
                $zscore_tid =  $this->redis->zscore($temporary_pain, $toID);
                if( $zscore_uid < $now_time ) {
                    // '主动触发,关闭页面,长时间未响应,用户不在线';
                    $this->redis->zrem($temporary_pain, $userID);
                    $this->redis->zrem($temporary_pain, $toID);
                    $result    = [
                        'code'    => WebSocketAction::SUCCESS_CODE, 
                        'message'  =>  '长时间未响应,用户不在线',
                        'status'   => WebSocketAction::msg_1005 //长时间未响应,用户不在线 隐藏div
                    ];
                    return  self::setMessage( $result, WebSocketAction::msg_1005 );
                } else {
                    $result    = [
                        'code'    => WebSocketAction::SUCCESS_CODE, 
                        'message'  =>  '您已经邀请此用户,请耐心等待',
                        'status'   => WebSocketAction::msg_1006 //您已经邀请此用户,请耐心等待
                    ];
                    return  self::setMessage( $result, WebSocketAction::msg_1006 );
                }
            }  else  if( $zrank_tid === 0 || $zrank_tid || $zrank1_tid === 0 || $zrank1_tid) {
                // '该用户在忙碌';
                $result    = [
                    'code'    => WebSocketAction::SUCCESS_CODE, 
                    'message'  =>  '该用户正在忙碌,请稍后',
                    'status'   => WebSocketAction::msg_1007 // '该用户正在忙碌,请稍后'
                ];
                return  self::setMessage( $result, WebSocketAction::msg_1007 );
            }  else {
                $this->redis->zadd($temporary_pain, $score, $userID);
                $this->redis->zadd($temporary_pain, $score, $toID);
                // 推人
                $redis_name_push = $push_user . $toID;//推送人员 list列表
                $redis_name_push_data = [
                    'uid'  => $userID,
                    'rid'  => $roomID,
                    'time' => time(),
                    'fd'   => $getFd,
                ];
                $this->redis->set($redis_name_push, json_encode( $redis_name_push_data, JSON_UNESCAPED_UNICODE ) );//推人 //推送人员 list列表 uid + rid
                self::redis_expire_time($redis_name_push, 30);
                //推送人
                $userInfo = self::selectUserInfo($userID);
                $sendData   = [
                    'action' => WebSocketAction::msg_1008,
                    'data'   => [
                        'roomId'       => !empty($roomID) ? $roomID : '',
                        'userId'       => $userID,
                        // 'fd'           => $getFd,
                        'startTime'    => time(),
                        'nowTime'      => time(),
                        'firstName'    => ! empty ( $userInfo['first_name'] ) ?  $userInfo['first_name'] : '',
                        'userName'     => ! empty ( $userInfo['user_name'] ) ?  $userInfo['user_name'] : '',
                        'icon'         => ! empty ( $userInfo['icon'] ) ?   WebSocketAction::URL . $userInfo['icon'] :  WebSocketAction::IMG_URL,
                    ]
                ];

                $flag =  self::push($toFD, $sendData);
                //异步通知 定时器
                self::delay($getFd, $roomID, $userID,$toID, $toFD);

                //计数
                $ver_get_video_request = WebSocketAction::ver_get_video_request;
                $this->redis->incr($ver_get_video_request);//键值递增

                $result    = [
                    'code'     => WebSocketAction::SUCCESS_CODE, 
                    'message'  =>  '初次邀请此用户,请耐心等待',
                    'status'   => WebSocketAction::msg_1006 // '您已经邀请此用户,请耐心等待'
                ];
                return  self::setMessage( $result, WebSocketAction::msg_1006 );
            }

        }
    }

     /**
     * agree 和 cancel
     * @throws \Exception
     */
    function OptionRoom() 
    {
        /** @var WebSocketClient $client */
        $getFd = $this->caller()->getClient()->getFd();
        $broadcastPayload = $this->caller()->getArgs();
        if ( !empty($broadcastPayload) ) {
            $userID    = $broadcastPayload['userID'];
            $toID      = $broadcastPayload['toID'];
            // $fd1        = ! empty ( $broadcastPayload['fd'] ) ? intval( $broadcastPayload['fd'] ) : '';
            $roomID    = ! empty ( $broadcastPayload['roomID'] ) ? intval( $broadcastPayload['roomID'] ) : '';
            $operation = $broadcastPayload['operation'];
            $operation_method = $this->operation_method;

            $toID1 = $toID;//零时占位
            if($roomID && $roomID != $toID) {
                $toID = $roomID;//在房间内邀请别人
            }
            if($userID == $toID) {
                $result['code']    = WebSocketAction::ERROR_CODE;
                $result['message']  = '自己不能邀请自己';
                return  self::setMessage( $result );
            }

            if( empty($operation) || !in_array($operation, $operation_method) )
            {
                $result['code']    = WebSocketAction::ERROR_CODE;
                $result['message']  = '操作型不能为空';
    
                return  self::setMessage( $result );
            }
            if(empty($userID) || !is_numeric($userID) || empty($toID) || !is_numeric($toID))
            {
                $result['code']    = WebSocketAction::ERROR_CODE;
                $result['message']  = '邀请人ID不能为空';
    
                return  self::setMessage( $result );
            }

            $temporary_pain = WebSocketAction::ver_get_temporary_pain;//零时
            $permanent_pain = WebSocketAction::ver_get_permanent_pain;//永久
            $push_user      = WebSocketAction::ver_get_push_user_info;//推送

            $success_invitation = WebSocketAction::ver_get_success_invitation;//成功邀请的列表 toID

            

            $redis_name_push = $push_user . $userID;//推送人员 list列表
            $userInfo  = $this->redis->get($redis_name_push);//删除推人
            $userInfo  = json_decode($userInfo, true);
            $fd        = ! empty ( $userInfo['fd'] ) ? intval( $userInfo['fd'] ) : '';
            // 删除推人
            $this->redis->del($redis_name_push);//删除推人

            //删除零时桶
            $this->redis->zrem($temporary_pain, $userID);
            $this->redis->zrem($temporary_pain, $toID);
            $this->redis->zrem($temporary_pain, $toID1);

            //发起成功邀请的列表 toID 的桶 记录FD
            $toFd_redis_name   = WebSocketAction::ver_get_success_invitation_fd . $toID;//成功邀请的列表 FD

            //发起成功邀请的列表 toID 的桶 记录userID
            $success_invitation = $success_invitation . $toID;

            $status = WebSocketAction::msg_1005;//长时间未响应,用户不在线
            if($operation == 'agree') {
                
                //发起成功邀请的列表 toID 的桶 记录FD
                if ( ! $this->redis->exists( $toFd_redis_name )  ) {
                    $this->redis->zadd($toFd_redis_name, time(), $getFd); 
                    self::redis_expire_time($toFd_redis_name, 7200);
                } else {
                    $this->redis->zadd($toFd_redis_name, time(), $getFd); 
                }

                //发起成功邀请的列表 toID 的桶 记录userID
                if ( ! $this->redis->exists( $success_invitation )  ) {
                    $this->redis->zadd($success_invitation, time(), $userID); 
                    self::redis_expire_time($success_invitation, 7200);
                } else {
                    $this->redis->zadd($success_invitation, time(), $userID); 
                }

                //保存永久时桶
                $this->redis->zadd($permanent_pain, time(), $userID); 
                $this->redis->zadd($permanent_pain, time(), $toID); 

                $status = WebSocketAction::msg_1004;//你们已经互通直播了


                //计数
                $ver_get_video_agree = WebSocketAction::ver_get_video_agree;
                $this->redis->incr($ver_get_video_agree);//键值递增

            } else if($operation == 'cancel') {
                $status =  WebSocketAction::msg_1009;//该用户拒绝了您
            }
            
            $message = [
                'add'     => '发起邀请成功',
                'agree'   => '通过成功',
                'cancel'  => '拒绝成功',
                'delete'  => '挂断成功',
            ];

            $result    = [
                'code'    => WebSocketAction::SUCCESS_CODE, 
                'message'  => $message[$operation],
                'userID'   => $userID,
                'roomID'   => $roomID,
                'status'   => $status // '您已经邀请此用户,请耐心等待'
            ];
            $result_error    = [
                'code'    => WebSocketAction::SUCCESS_CODE, 
                'message'  => '发起邀请人意外退出',
                'status'   => WebSocketAction::msg_1010,  // web页面刷新 丢失 fd
            ];
            $sendData = [
                'action' => $status,
                'data'   => $result,
            ];
            $flag =  self::push($fd, $sendData);
            if($flag) {
                self::setMessage( $result, $status );
            } else {
                //发起成功邀请的列表 toID 的桶 记录FD
                $this->redis->zrem($toFd_redis_name, $getFd); 
                //发起成功邀请的列表 toID 的桶 记录userID
                $this->redis->zrem($success_invitation, $userID); 
                //保存永久时桶
                $this->redis->zrem($permanent_pain, $userID); 
                $this->redis->zrem($permanent_pain, $toID); 

                self::setMessage( $result_error, WebSocketAction::msg_1010 );
            }
           
        }    
    }

    /**
     * 退出房间
     */
    public function exitRoom() 
    {
        /** @var WebSocketClient $client */
        $getFd = $this->caller()->getClient()->getFd();
        $broadcastPayload = $this->caller()->getArgs();
        if ( !empty($broadcastPayload) ) {
            $operation = $broadcastPayload['operation'];
            $userID    = $broadcastPayload['userID'];
            // $toID      = $broadcastPayload['toID'];
            $roomID    = ! empty ( $broadcastPayload['roomID'] ) ? intval( $broadcastPayload['roomID'] ) : '';
            $operation_method = [
                'closeOne',
                'closeAll',
            ];

            $toID = $roomID;

            if( empty($operation) || !in_array($operation, $operation_method) )
            {
                $result['code']    = WebSocketAction::ERROR_CODE;
                $result['message']  = '操作型不能为空';
                return  self::setMessage( $result );
                
            }

            if(empty($roomID) || !is_numeric($roomID))
            {
                $result['code']    = WebSocketAction::ERROR_CODE;
                $result['message']  = '直播间ID不能为空';
                return  self::setMessage( $result );
            }
           

            $permanent_pain = WebSocketAction::ver_get_permanent_pain;//永久
            $temporary_pain = WebSocketAction::ver_get_temporary_pain;//零时

            $success_invitation = WebSocketAction::ver_get_success_invitation;//成功邀请的列表 toID
            //发起成功邀请的列表 toID 的桶 记录FD
            $toFd_redis_name   = WebSocketAction::ver_get_success_invitation_fd . $toID;//成功邀请的列表 FD

            //发起成功邀请的列表 toID 的桶 记录userID
            $success_invitation = $success_invitation . $toID;

            //作者本人退出
            if($operation == 'closeAll') {
                //保存永久时桶
                $this->redis->zrem($permanent_pain, $toID); 
                //成功邀请的列表 toID
                if ( $this->redis->exists( $success_invitation ) ) {
                    $success_invitation_info = $this->redis->zrevrange($success_invitation, 0, -1);
                    for ($i=0; $i < count($success_invitation_info); $i++) { 

                        $success_invitation_userID = $success_invitation_info[$i];
                        //删除永久桶记录人
                        $this->redis->zrem($permanent_pain, $success_invitation_userID); 
                        //删除零时桶记录人
                        $this->redis->zrem($temporary_pain, $success_invitation_userID); 
                    }
                    //删除房间主人成功邀请
                    $this->redis->del($success_invitation);
                }

                //成功邀请的列表 FD
                if ( $this->redis->exists( $toFd_redis_name ) ) {
                    $sendData    = [
                        'action' => WebSocketAction::msg_1011, //房主退出,
                        'data'   => [
                            'code'    =>  WebSocketAction::SUCCESS_CODE, 
                            'message'  => '房主退出,房间不存在',
                            'status'   => WebSocketAction::msg_1011 //房主退出
                        ]
                    ];
                    //成功邀请的列表 FD
                    $toFd_redis_name_info = $this->redis->zrevrange($toFd_redis_name, 0, -1);
                    for ($i=0; $i < count($toFd_redis_name_info); $i++) { 
                        //fd推送
                        $toFd_redis_name_info_FD = $toFd_redis_name_info[$i];
                        self::push($toFd_redis_name_info_FD, $sendData);
                    }
                    //删除房间主人成功邀请
                    $this->redis->del($toFd_redis_name);
                }

                //检测邀请的用户是否在播 删除主播占位房间
                $this->redis->del('room_rid_'. $roomID);
                $this->redis->del('room_fd_'. $getFd);

                //计数
                $ver_get_video_number = WebSocketAction::ver_get_video_number;
                $this->redis->incr($ver_get_video_number);//键值递增
            } else if($operation == 'closeOne') {

                if(empty($userID) || !is_numeric($userID))
                {
                    $result['code']    = WebSocketAction::ERROR_CODE;
                    $result['message']  = '用户ID不能为空';
                    return  self::setMessage( $result );
                }
                //删除永久桶记录人
                $this->redis->zrem($permanent_pain, $userID); 
                //删除零时桶记录人
                $this->redis->zrem($temporary_pain, $userID); 
                //接收邀请人的房间也退出
                $this->redis->zrem($success_invitation, $userID); 

            }

            $message = [
                'closeOne'  => '单个用户退出成功',
                'closeAll'  => '全部用户清空成功',
            ];
    
            $result    = [
                'code'    => 200, 
                'message'  => $message[$operation],
                'status'   => WebSocketAction::msg_1011 //房主退出
            ];
            return  self::setMessage( $result, WebSocketAction::msg_1011 );
        }
    }
    
    
    /*
    * HTTP触发向某个客户端单独推送消息
    * @example http://ip:9501/WebSocketTest/push?fd=2
    */
    public function push($fd = '', $message= [])
    {
        if (is_numeric($fd) && ! empty($fd) ) {
            $fd = intval($fd);
            /** @var \swoole_websocket_server $server */
            $server = ServerManager::getInstance()->getSwooleServer();
            $info = $server->getClientInfo($fd);
            if ($info && $info['websocket_status'] == WEBSOCKET_STATUS_FRAME) {
                $server->push($fd, json_encode( $message, JSON_UNESCAPED_UNICODE ));
                return true;
            } else {
                self::setMessage("fd {$fd} is not exist or closed");
            }
        } else {
            self::setMessage("fd {$fd} is invalid");
        }
    }

    

    /**
     * 获取个人信息
     */
    public function selectUserInfo( $ID = '' ) {
        if(!$ID) { return false;}
        //mysql配置
        $db    = MysqlPool::defer();
        $return_data = [];
        $UsersModel = new UsersModel($db);
        $sql  = 'SELECT user_id, first_name, user_name, touxiang as icon FROM p46_users WHERE  user_id = ' . $ID . ' ';
        @$dbData   = $UsersModel->getloadMoreKeys($sql);
        if($dbData) {
            $return_data  =  $dbData[0];
        }
        return $return_data;
    }
    /**
     * PING
     */
    function heartbeat()
    {
        $getFd   = $this->caller()->getClient()->getFd();
        $Payload  = $this->caller()->getArgs();
        $userID   = ! empty ( $Payload['userID'] ) && is_numeric( $Payload['userID'] ) ? trim( $Payload['userID'] ) : '';
        if( $userID && is_numeric( $userID ) && $getFd && is_numeric( $getFd )  ) {
            $redis_name_user = WebSocketAction::ver_get_web_socket_user . $userID;
            // if( $this->redis->exists( $redis_name_user) ) {
            //     $oldFD = $this->redis->get($redis_name_user);//得到 老的 FD
            //     if($oldFD != $getFd) {
            //         $oldFD_redis_name   = WebSocketAction::ver_get_web_socket_fd . $oldFD;
            //         $this->redis->del($oldFD_redis_name);
            //     }
            // }

            $redis_name_fd   = WebSocketAction::ver_get_web_socket_fd . $getFd;
            $this->redis->set($redis_name_user , $getFd);
            $this->redis->set($redis_name_fd   , $userID);
            self::redis_expire_time($redis_name_user, 7200);
            self::redis_expire_time($redis_name_fd, 7200);
            $this->response()->setMessage('PONG_'. $getFd . '_' . $userID);
        } else {
            $this->response()->setMessage('PONG_'. $getFd);
        }
    }

    /**
     * PING
     */
    function heartbeat1()
    {
        $this->response()->setMessage('PONG');
    }
    /**
     * 返回结果的处理
     */
    function setMessage(  $msg = '', $action = WebSocketAction::msg_1001) {
        $data = [
            'action' => $action,
            'data'   => $msg
        ];
        return  $this->response()->setMessage(json_encode( $data, JSON_UNESCAPED_UNICODE ) );
    }

    public  function redis_expire_time($key, $seconds) {
        if ($this->redis->exists($key)) {
            return $this->redis->expire($key, $seconds);
        }
        return false;
    }

    /**
     * 会议管理
     */
    public function createMeeting() {
        /** @var WebSocketClient $client */
        $getFd = $this->caller()->getClient()->getFd();
        $broadcastPayload = $this->caller()->getArgs();
        if ( !empty($broadcastPayload) ) {
            $userID    = $broadcastPayload['userID'];
            $roomID    = ! empty ( $broadcastPayload['roomID'] ) ? intval( $broadcastPayload['roomID'] ) : '';
            $operation = $broadcastPayload['operation'];
            $operation_method = [
                'create',
                'enter',
                'closeAll',
                'closeOne'
            ];
            if($operation == 'create') {
                //房间ID
                if(! $roomID) {
                    $roomID = $userID;
                }
            }
            if( empty($operation) || !in_array($operation, $operation_method) )
            {
                $result['code']     = WebSocketAction::ERROR_CODE;
                $result['message']  = '操作型不能为空';
    
                return  self::setMessage( $result );
            }
            if(empty($userID) || !is_numeric($userID) || empty($roomID) || !is_numeric($roomID))
            {
                $result['code']     = WebSocketAction::ERROR_CODE;
                $result['message']  = '用户或房间的ID不能为空';
    
                return  self::setMessage( $result );
            }

            //创建
            if($operation == 'create') {
                //房间ID & userID
                $redis_name_user  = WebSocketAction::ver_get_room_management . 'user_' . $userID;
                $this->redis->set($redis_name_user, $roomID);
                self::redis_expire_time($redis_name_user, 86400);
                //房间ID
                $redis_name = WebSocketAction::ver_get_room_management . $roomID;
                $this->redis->set($redis_name, $userID);
                self::redis_expire_time($redis_name, 86400);

                $result['code']     = WebSocketAction::SUCCESS_CODE;
                $result['message']  = '创建房间成功';
                $result['status']   =  WebSocketAction::msg_1014;
                return  self::setMessage( $result, WebSocketAction::msg_1014 );
            //游客 进入房间
            } else if ($operation == 'enter') {

                $redis_name = WebSocketAction::ver_get_room_management . $roomID;
                if ( ! $this->redis->exists( $redis_name )  ) { 
                    $result['code']     = WebSocketAction::SUCCESS_CODE;
                    $result['message']  = '此房间不存在';
                    $result['status']   =  WebSocketAction::msg_1016;
                    return  self::setMessage( $result, WebSocketAction::msg_1016 );
                } else {
                    $redis_name_meeting_room      = WebSocketAction::ver_get_room_management_info . $roomID;
                    $ver_get_room_management_fd   = WebSocketAction::ver_get_room_management_fd . $roomID;
                    /*$this->redis->zadd($redis_name_meeting_room, time(), $userID);
                    $this->redis->zadd($ver_get_room_management_fd, time(), $getFd);*/
                    
                    if ( ! $this->redis->exists( $redis_name_meeting_room )  ) {
                        $this->redis->zadd($redis_name_meeting_room, time(), $userID); 
                        self::redis_expire_time($redis_name_meeting_room, 7200);
                    } else {
                        $this->redis->zadd($redis_name_meeting_room, time(), $userID); 
                    }

                    if ( ! $this->redis->exists( $ver_get_room_management_fd )  ) {
                        $this->redis->zadd($ver_get_room_management_fd, time(), $getFd); 
                        self::redis_expire_time($ver_get_room_management_fd, 7200);
                    } else {
                        $this->redis->zadd($ver_get_room_management_fd, time(), $getFd); 
                    }
                
                    $result['code']     = WebSocketAction::SUCCESS_CODE;
                    $result['message']  = '进入房间成功';
                    $result['status']   =  WebSocketAction::msg_1015;
                    return  self::setMessage( $result, WebSocketAction::msg_1015 );
                }
            //销毁房间    
            }   else if ($operation == 'closeAll') { 
                //房间内的人源的用户ID
                $redis_name_meeting_room      = WebSocketAction::ver_get_room_management_info . $roomID;
                //删除房间主人成功邀请列表
                $this->redis->del($redis_name_meeting_room);

                //检测房间的key
                $redis_name = WebSocketAction::ver_get_room_management . $roomID;
                $this->redis->del($redis_name);

                //检测房间的key 和创建人
                $redis_name_1  = WebSocketAction::ver_get_room_management . 'user_' . $userID;
                $this->redis->del($redis_name_1);

                //房间内的人源的FD
                $ver_get_room_management_fd   = WebSocketAction::ver_get_room_management_fd . $roomID;
                //成功邀请的列表 FD
                if ( $this->redis->exists( $ver_get_room_management_fd ) ) {
                    $sendData    = [
                        'action' => WebSocketAction::msg_1017, //房主退出,推送房内人员的各个FD
                        'data'   => [
                            'code'    =>  WebSocketAction::SUCCESS_CODE, 
                            'message'  => '发起邀请人销毁房间,房间不存在',
                            'status'   => WebSocketAction::msg_1017 //房主退出
                        ]
                    ];
                    //成功邀请的列表 FD
                    $toFd_redis_name_info = $this->redis->zrevrange($ver_get_room_management_fd, 0, -1);
                    for ($i=0; $i < count($toFd_redis_name_info); $i++) { 
                        //fd推送
                        $toFd_redis_name_info_FD = $toFd_redis_name_info[$i];
                        self::push($toFd_redis_name_info_FD, $sendData);
                    }
                    //删除房间主人成功邀请
                    $this->redis->del($ver_get_room_management_fd);
                }

                $result['code']     =  WebSocketAction::SUCCESS_CODE;
                $result['message']  = '房主退房';
                $result['status']   =  WebSocketAction::msg_1018;
                return  self::setMessage( $result, WebSocketAction::msg_1018 );

            //个人退出        
            }  else if ($operation == 'closeOne') { 

                $redis_name_meeting_room      = WebSocketAction::ver_get_room_management_info . $roomID;
                $ver_get_room_management_fd   = WebSocketAction::ver_get_room_management_fd . $roomID;
                $this->redis->zrem($redis_name_meeting_room, $userID);
                $this->redis->zrem($ver_get_room_management_fd, $getFd);
                $result['code']     =  WebSocketAction::SUCCESS_CODE;
                $result['message']  = '个人退房';
                $result['status']   =  WebSocketAction::msg_1019;
                return  self::setMessage( $result, WebSocketAction::msg_1019 );
            }

        }
    }
}