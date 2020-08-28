<?php
namespace App\HttpController;
use EasySwoole\Http\AbstractInterface\Controller;

use EasySwoole\Http\Message\Status;
use EasySwoole\Http\Response;

/**
 * *
 *
 * @author frank
 *
 */
class Healthz extends Controller
{
    function index()
    {
        $this->writeJson(Status::CODE_OK, '', 'success');

    }

    public function writeJson($statusCode = 200, $data = null)
    {
        if (! $this->response()->isEndResponse()) {
            $this->response()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->response()->withHeader('Content-type', 'application/json;charset=utf-8');
            $this->response()->withStatus($statusCode);
            $this->response()->end();
        } else {
            $this->response()->withHeader('Content-type', 'application/json;charset=utf-8');
            $this->response()->withStatus(Status::CODE_INTERNAL_SERVER_ERROR);
            $this->response()->end();
        }
    }

}

