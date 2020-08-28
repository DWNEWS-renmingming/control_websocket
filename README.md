# 控制邀请 长链接
WEBSOCKET 服务
composer install
php vendor/easyswoole/easyswoole/bin/easyswoole install
composer dump-autoload

http://www.easyswoole.com/wstool.html

ws://127.0.0.1:9501

测试 hello
{"controller":"Index","action":"hello","params":{"userID":3433,"operation":"userInfo"}}


测试 list
{"controller":"Index","action":"roomList","params":{"userID":"235","operation":"list"}}

add  & delete
{"controller":"Index","action":"IntoRoom","params":{"userID":"236","toID":"235","roomID":"236","operation":"add"}}

{"controller":"Index","action":"IntoRoom","params":{"userID":"236","toID":"235","operation":"delete"}}


agree

{"controller":"Index","action":"OptionRoom","params":{"userID":"235","toID":"236","roomID":"236","operation":"agree"}}

cancel
{"controller":"Index","action":"OptionRoom","params":{"userID":"235","toID":"236","operation":"cancel"}}

房主退房
{"controller":"Index","action":"exitRoom","params":{"userID":"235","roomID":"236","toID":"236","operation":"closeAll"}}
个人退房
{"controller":"Index","action":"exitRoom","params":{"userID":"235","roomID":"236","toID":"236","operation":"closeOne"}}
-------

检测房间是否存在
{"controller":"Index","action":"detectionRoom","params":{"userID":"235","roomID":"236"}}


status:1  您已经邀请此用户,请耐心等待
status:2  你们已经互通直播了
status:3  该用户拒绝了您！！
status:4  该用户正在忙碌,请稍后

status:5  主动挂断成功
status:6  长时间未响应,用户不在线 div
status:7  web页面刷新 丢失 fd
status:8  房主退出