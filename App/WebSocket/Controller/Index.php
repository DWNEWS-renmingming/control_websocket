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

    public $db;

    public function __construct() 
    {
        //mysql配置
        $this->db    = MysqlPool::defer();
        //redis配置
        $this->redis = RedisPool::defer();
        //继承父类的的 __construct
        parent::__construct();
    }

    function hello()
    {
        $this->response()->setMessage('call hello with arg:'. json_encode($this->caller()->getArgs()));
    }

    public function who(){
        $this->response()->setMessage('your fd is '. $this->caller()->getClient()->getFd());
    }

    public $operation_method = [ 
        'add',   
        'agree',
        'cancel',
        'delete',
    ];

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

            if($userID == $toID) {
                $result['error']    = WebSocketAction::ERROR_CODE;
                $result['message']  = '自己不能邀请自己';
                return  self::setMessage( $result );
            }

            if( empty($operation) || !in_array($operation, $operation_method) )
            {
                $result['error']    = WebSocketAction::ERROR_CODE;
                $result['message']  = '操作型不能为空';
    
                return  self::setMessage( $result );
            }
            if(empty($userID) || !is_numeric($userID) || empty($toID) || !is_numeric($toID))
            {
                $result['error']    = WebSocketAction::ERROR_CODE;
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
                'delete'  => '挂断成功',
            ];

            $temporary_pain = WebSocketAction::ver_get_temporary_pain;//零时
            $permanent_pain = WebSocketAction::ver_get_permanent_pain;//永久
            $push_user      = WebSocketAction::ver_get_push_user_info;//推送

            $success_invitation = WebSocketAction::ver_get_success_invitation;;//成功邀请的列表

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

                $result    = [
                    'error'    => 0, 
                    'message'  =>  $message[$operation],
                    'status'   => 5 //主动挂断成功
                ];
                return  self::setMessage( $result );
            }

            //检测toID是否在永久桶里面  我的成功邀请的列表
            $success_invitation = $success_invitation . $userID;
            $zrank_success_invitation =  $this->redis->zrank($success_invitation, $toID );//我的成功邀请列表
            //我的成果邀请列表存在 toID
            if( $zrank_success_invitation === 0 || $zrank_success_invitation) {  
                $result    = [
                    'error'    => 0, 
                    'message'  =>  '你们已经互通直播了',
                    'status'   => 2 //你们已经互通直播了
                ];
                return  self::setMessage( $result );
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
                        'error'    => 0, 
                        'message'  =>  '长时间未响应,用户不在线',
                        'status'   => 6 //长时间未响应,用户不在线 隐藏div
                    ];
                    return  self::setMessage( $result );
                } else {
                    $result    = [
                        'error'    => 0, 
                        'message'  =>  '您已经邀请此用户,请耐心等待',
                        'status'   => 1 //您已经邀请此用户,请耐心等待
                    ];
                    return  self::setMessage( $result );
                }
            }  else  if( $zrank_tid === 0 || $zrank_tid || $zrank1_tid === 0 || $zrank1_tid) {
                // '该用户在忙碌';
                $result    = [
                    'error'    => 0, 
                    'message'  =>  '该用户正在忙碌,请稍后',
                    'status'   => 4 // '该用户正在忙碌,请稍后'
                ];
                return  self::setMessage( $result );
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
                $result    = [
                    'error'    => 0, 
                    'message'  =>  '您已经邀请此用户,请耐心等待',
                    'status'   => 1 // '您已经邀请此用户,请耐心等待'
                ];
                return  self::setMessage( $result );
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
                $result['error']    = WebSocketAction::ERROR_CODE;
                $result['message']  = '自己不能邀请自己';
                return  self::setMessage( $result );
            }

            if( empty($operation) || !in_array($operation, $operation_method) )
            {
                $result['error']    = WebSocketAction::ERROR_CODE;
                $result['message']  = '操作型不能为空';
    
                return  self::setMessage( $result );
            }
            if(empty($userID) || !is_numeric($userID) || empty($toID) || !is_numeric($toID))
            {
                $result['error']    = WebSocketAction::ERROR_CODE;
                $result['message']  = '邀请人ID不能为空';
    
                return  self::setMessage( $result );
            }

            $temporary_pain = WebSocketAction::ver_get_temporary_pain;//零时
            $permanent_pain = WebSocketAction::ver_get_permanent_pain;//永久
            $push_user      = WebSocketAction::ver_get_push_user_info;//推送

            $success_invitation = WebSocketAction::ver_get_success_invitation;;//成功邀请的列表 toID

            

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

            $status = 6;//
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

                $status = 2;


            } else if($operation == 'cancel') {
                $status = 3;
            }
            
            $message = [
                'add'     => '发起邀请成功',
                'agree'   => '通过成功',
                'cancel'  => '拒绝成功',
                'delete'  => '挂断成功',
            ];

            $result    = [
                'error'    => 0, 
                'message'  => $message[$operation],
                'status'   => $status // '您已经邀请此用户,请耐心等待'
            ];
            $result_error    = [
                'error'    => 0, 
                'message'  => '邀请人意外退出',
                'status'   => 7 // web页面刷新 丢失 fd
            ];
            $flag =  self::push($fd, $result);
            if($flag) {
                self::setMessage( $result );
            } else {
                //发起成功邀请的列表 toID 的桶 记录FD
                $this->redis->zrem($toFd_redis_name, $getFd); 
                //发起成功邀请的列表 toID 的桶 记录userID
                $this->redis->zrem($success_invitation, $userID); 
                //保存永久时桶
                $this->redis->zrem($permanent_pain, $userID); 
                $this->redis->zrem($permanent_pain, $toID); 

                self::setMessage( $result_error );
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
            $toID      = $broadcastPayload['toID'];
            $roomID    = ! empty ( $broadcastPayload['roomID'] ) ? intval( $broadcastPayload['roomID'] ) : '';
            $operation_method = [
                'closeOne',
                'closeAll',
            ];
            if( empty($operation) || !in_array($operation, $operation_method) )
            {
                $result['error']    = WebSocketAction::ERROR_CODE;
                $result['message']  = '操作型不能为空';
                return  self::setMessage( $result );
                
            }

            if(empty($toID) || !is_numeric($toID))
            {
                $result['error']    = WebSocketAction::ERROR_CODE;
                $result['message']  = '直播间ID不能为空';
                return  self::setMessage( $result );
            }
    
            if($toID == $userID)
            {
                $result['error']    =  WebSocketAction::ERROR_CODE;
                $result['message']  = 'ID不能相同';
                return  self::setMessage( $result );
            }

            if(!$roomID) {
                $roomID  = $toID;
            }

            $permanent_pain = WebSocketAction::ver_get_permanent_pain;//永久
            $temporary_pain = WebSocketAction::ver_get_temporary_pain;//零时

            $success_invitation = WebSocketAction::ver_get_success_invitation;;//成功邀请的列表 toID
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
                    $result_FD    = [
                        'error'    => 0, 
                        'message'  => '房主退出',
                        'status'   => 8 //房主退出
                    ];
                    //成功邀请的列表 FD
                    $toFd_redis_name_info = $this->redis->zrevrange($toFd_redis_name, 0, -1);
                    for ($i=0; $i < count($toFd_redis_name_info); $i++) { 
                        //fd推送
                        $toFd_redis_name_info_FD = $toFd_redis_name_info[$i];
                        self::push($toFd_redis_name_info_FD, $result_FD);
                    }
                    //删除房间主人成功邀请
                    $this->redis->del($toFd_redis_name);
                }

                //检测邀请的用户是否在播 删除主播占位房间
                $this->redis->del('room_rid_'. $roomID);
                $this->redis->del('room_fd_'. $getFd);
            } else if($operation == 'closeOne') {

                if(empty($userID) || !is_numeric($userID))
                {
                    $result['error']    = 1;
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
                'error'    => 0, 
                'message'  =>  $message[$operation],
                'status'   => 8 //房主退出
            ];
            return  self::setMessage( $result );
        }
    }
    
    /**
     * 检测房间是否存在
     */
    public function detectionRoom() 
    {
        /** @var WebSocketClient $client */
        $getFd = $this->caller()->getClient()->getFd();
        $broadcastPayload = $this->caller()->getArgs();
        if ( !empty($broadcastPayload) ) {
            $userID    = $broadcastPayload['userID'];
            $roomID    = ! empty ( $broadcastPayload['roomID'] ) ? intval( $broadcastPayload['roomID'] ) : '';
            if(empty($roomID) || !is_numeric($roomID))
            {
                $result['error']    = WebSocketAction::ERROR_CODE;
                $result['message']  = '直播间ID不能为空';
                return  self::setMessage( $result );
            }
    
            if(empty($userID) || !is_numeric($userID))
            {
                $result['error']    =  WebSocketAction::ERROR_CODE;
                $result['message']  = '用户ID不能相同';
                return  self::setMessage( $result );
            }
          
            $status = 0;
            if ( $this->redis->exists('room_rid_'. $roomID ) ) {
                $status = 1;
            } 
            $result    = [
                'error'    => 0, 
                'message'  =>  '验证直播间是否存在',
                'status'   => $status //房主退出
            ];
            return  self::setMessage( $result );
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
     * 获取个人列表
     * @throws \Exception
     */
    function roomList()
    {
        /** @var WebSocketClient $client */
        $client = $this->caller()->getClient();
        $broadcastPayload = $this->caller()->getArgs();
        if ( !empty($broadcastPayload) ) {
            $userID    = $broadcastPayload['userID'];
            $operation = $broadcastPayload['operation'];
            if(empty($userID) || !is_numeric($userID))
            {
                $result['error']    = WebSocketAction::ERROR_CODE;
                $result['message']  = '直播间ID不能为空';
                return  self::setMessage( $result );
            }
            $operation_method = [
                'list',
                'userInfo',
            ];
            if( empty($operation) || !in_array($operation, $operation_method) )
            {
                $result['error']    = WebSocketAction::ERROR_CODE;
                $result['message']  = '操作型不能为空';
                return  self::setMessage( $result );
            }

            $redis_name = WebSocketAction::ver_get_push_user_info . $userID;
            //直播总人数
            $reeturn_data = $data = [];
            if($operation == 'list') {
                if ($this->redis->exists($redis_name)) {
                    $redis_data = $this->redis->get($redis_name);
                    $room_data  =  json_decode($redis_data, true);
                    $ID         = $room_data['uid'];
                    $fd         = $room_data['fd'];
                    $room_id    = $room_data['rid'];
                    $start_time = $room_data['time'];
                    $now_time   = time();
                    $userInfo = self::selectUserInfo($ID);
                    $data     = [
                        'room_id'      => !empty($room_id) ? $room_id : '',
                        'user_id'      => $ID,
                        'fd'           => $fd,
                        'start_time'   => $start_time,
                        'now_time'     => $now_time,
                        'first_name'   => ! empty ( $userInfo['first_name'] ) ?  $userInfo['first_name'] : '',
                        'icon'         => ! empty ( $userInfo['icon'] ) ?   WebSocketAction::URL . $userInfo['icon'] :  WebSocketAction::IMG_URL,
                    ];
                }
            } else if($operation == 'userInfo') {
                $userInfo = self::selectUserInfo($userID);
                $data     = [
                    'user_id'    => $userID,
                    'first_name' => ! empty ( $userInfo['first_name'] ) ?  $userInfo['first_name'] : '',
                    'icon'       => ! empty ( $userInfo['icon'] ) ?   WebSocketAction::URL . $userInfo['icon'] :  WebSocketAction::IMG_URL,
                ];
            }

            $reeturn_data  = [
                'list'         =>  ! empty( $data ) ? $data : null,
            ];
            $message = [
                'list'      => '获取本场受邀请的用户',
                'userInfo'  => '获取用户基本信息',
            ];
            $result['error']    = WebSocketAction::CODE;
            $result['message']  = $message[$operation];
            $result['result']   = $reeturn_data;

            self::setMessage( $result );
        }
    }

    /**
     * 获取个人信息
     */
    public function selectUserInfo( $ID = '' ) {
        if(!$ID) { return false;}
        $return_data = [];
        $UsersModel = new UsersModel($this->db);
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
        $this->response()->setMessage('PONG');
    }
    /**
     * 返回结果的处理
     */
    function setMessage( $data = []) {
        return  $this->response()->setMessage(json_encode( $data, JSON_UNESCAPED_UNICODE ) );
    }

    public  function redis_expire_time($key, $seconds) {
        if ($this->redis->exists($key)) {
            return $this->redis->expire($key, $seconds);
        }
        return false;
    }

}