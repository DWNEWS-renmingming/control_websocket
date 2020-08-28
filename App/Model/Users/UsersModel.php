<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/11/28
 * Time: 11:47 AM
 */
namespace App\Model\Users;

use App\Model\BaseModel;

class UsersModel extends BaseModel
{

    protected $table = 'p46_users';

    function getloadMoreKeys($sql)
    {
        $data = $this->getDb()->rawQuery($sql);
        return $data ? $data : [];
    }

}
