# 控制邀请 长链接
WEBSOCKET 服务
composer install
php vendor/easyswoole/easyswoole/bin/easyswoole install
composer dump-autoload

http://www.easyswoole.com/wstool.html

ws://127.0.0.1:9501

2 => 'IntoRoom',
3 => 'OptionRoom',
4 => 'exitRoom',
5 => 'createMeeting',
99 => 'heartbeat1',


add  & delete
##{"action":"2","params":{"userID":"236","toID":"235","roomID":"236","operation":"add"}}
##{"action":"2","params":{"userID":"236","toID":"235","roomID":"236","operation":"delete"}}

agree or cancel
{"action":"3","params":{"userID":"235","toID":"236","roomID":"236","operation":"agree"}}
{"action":"3","params":{"userID":"235","toID":"236","roomID":"236","operation":"cancel"}}

退出房间
{"action":"4","params":{"userID":"235","roomID":"236","operation":"closeAll"}}
{"action":"4","params":{"userID":"235","roomID":"236","operation":"closeOne"}}



 会议管理
{"action":"5","params":{"userID":"235","roomID":"236","operation":"create"}}
{"action":"5","params":{"userID":"235","roomID":"236","operation":"enter"}}
{"action":"5","params":{"userID":"235","roomID":"236","operation":"closeAll"}}
{"action":"5","params":{"userID":"235","roomID":"236","operation":"closeOne"}}

返回消息类型
1001   请求参数有问题
1002   对方不在线
1003   主动挂断
1004   你们已经互通直播了
1005   长时间未响应,用户不在线
1006   您已经邀请此用户请耐性等待
1007   该用户正在忙碌,请稍等在拨
1008   推送人list列列表
1009   该用户拒绝了您
1010   发起邀请人意外退出 | / web页面刷新 丢失 fd
1011   房主退出,房间不存在
1012   主动挂断成功
1013   超时未操作,自动退出

1014   作者创建房间
1015   游客进入房间 检测房间是是否存在  存在
1016   游客进入房间 检测房间是是否存在  不存在
1017   作者退出房间 触发FD 推送
1018   房主退房
1019   个人退房





location /websocket {
    proxy_pass http://backend;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
}