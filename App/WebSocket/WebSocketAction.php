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

    const URL                          = 'https://etest.eovobochina.com/';//url
    const IMG_URL                      = 'https://etest.eovobochina.com/data/touxiang/1597491604545676098.jpg';//url
  
    const ERROR_CODE = 1;          // 参数错误
    const CODE = 0;                // 参数错误


}