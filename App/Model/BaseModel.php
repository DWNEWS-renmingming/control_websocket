<?php
/**
 * @author Frank
 * 2019-04-20
 */
namespace App\Model;

use App\Utility\Pool\MysqlObject;

/**
 * Class BaseModel
 *
 * @package App\Model
 */
class BaseModel
{

    private $db;

    function __construct(MysqlObject $dbObject)
    {
        $this->db = $dbObject;
    }

    protected function getDb(): MysqlObject
    {
        return $this->db;
    }

    function getDbConnection(): MysqlObject
    {
        return $this->db;
    }
}
