<?php
/**
 * Created by PhpStorm.
 * User: evalor
 * Date: 2018-12-02
 * Time: 01:44
 */

namespace App\WebSocket;

class WebSocketAction
{
    #######redis########
    const ver_get_push_user_info        = 'ver_wz_push_user_';//我收到的邀请list 推送列表
    const ver_get_temporary_pain        = 'ver_wz_temporary_pain';//零时房间
    const ver_get_permanent_pain        = 'ver_wz_permanent_pain';//永久房间
    const ver_get_success_invitation    = 'ver_wz_success_invitation_';//成功邀请的列表 toID
    const ver_get_success_invitation_fd = 'ver_wz_success_invitation_fd_';//成功邀请的列表 FD


    const ver_get_web_socket_user       = 'ver_wz_web_socket_user_';    
    const ver_get_web_socket_fd         = 'ver_wz_web_socket_fd_';    

    const URL                          = 'https://etest.eovobochina.com/';//url
    const IMG_URL                      = 'https://etest.eovobochina.com/data/touxiang/1597491604545676098.jpg';//url
  
    //返回消息类型
    const msg_1001      = 1001;//请求参数有问题
    const msg_1002      = 1002;//对方不在线
    const msg_1003      = 1003;//主动挂断
    const msg_1004      = 1004;//你们已经互通直播了
    const msg_1005      = 1005;//长时间未响应,用户不在线
    const msg_1006      = 1006;//您已经邀请此用户请耐性等待
    const msg_1007      = 1007;//该用户正在忙碌,请稍等在拨
    const msg_1008      = 1008;//推送人list列列表
    const msg_1009      = 1009;//该用户拒绝了您
    const msg_1010      = 1010;//发起邀请人意外退出 | / web页面刷新 丢失 fd
    const msg_1011      = 1011;//房主退出,房间不存在


    const SUCCESS_CODE  = 200; //成功
    const ERROR_CODE    = 400; // 参数错误


    //2 => 'IntoRoom',
    //3 => 'OptionRoom',
    //4 => 'exitRoom',
    //99 => 'heartbeat1',
/*
    add  & delete
    {"action":"2","params":{"userID":"236","toID":"235","roomID":"236","operation":"add"}}
    {"action":"2","params":{"userID":"236","toID":"235","roomID":"236","operation":"delete"}}

    agree or cancel
    {"action":"3","params":{"userID":"235","toID":"236","roomID":"236","operation":"agree"}}
    {"action":"3","params":{"userID":"235","toID":"236","roomID":"236","operation":"cancel"}}

    退出房间
    {"action":"4","params":{"userID":"235","roomID":"236","toID":"236","operation":"closeAll"}}
    {"action":"4","params":{"userID":"235","roomID":"236","toID":"236","operation":"closeOne"}}
*/
}