<?php


namespace KongTools;


class KongTools
{
    /**
     * 批量更新SQL拼接
     * @param array $result 数据更新数组
     * @param string $whenField 本次更新数据中不重复的数据库字段
     * @param array $operation 本次更新数据中需要加减的数组  ['num'=>'-']
     * @return array
     *
     * foreach ($when as $fieldName => &$item) {
     * $item = DB::raw("case " . implode(' ', $item) . ' end ');
     * }
     *
     */
    public static function batchUpdate(array $result = [], $whenField = 'id', $operation = [])
    {
        $when = [];
        foreach ($result as $sets) {
            foreach ($sets as $fieldName => $value) {
                if ($fieldName == $whenField) {
                    continue;
                }
                if (is_null($value)) {
                    $value = ' ';
                }
                if (isset($operation[$fieldName])) {
                    $value = $fieldName . $operation[$fieldName] . $value;
                } else {
                    $value = "'" . $value . "'";
                }
                $when[$fieldName][] =
                    "when {$whenField} = '{$sets[$whenField]}' then " . $value;
            }
        }
        return $when;
    }

    /**
     * 修改config下配置文件  Ps：只更新一维数组的配置文件
     *
     * @param array $param 数据更新数组
     * @param string $config_file 文件绝对路径
     *
     * @return bool|int
     */
    public static function setConfig(array $param, $config_file)
    {
        if (count($param) == 0) {//无更新直接返回错误
            return false;
        }
        $config = @file_get_contents($config_file);
        $callback = function ($matches) use ($param) {
            $field = $matches[1];
            $replace = $param[$field];
            return "'{$matches[1]}'{$matches[2]}=>{$matches[3]}'{$replace}',";
        };
        $match_str = implode('|', array_keys($param));
        $config =
            preg_replace_callback("/'({$match_str})'(\s*)=>(\s*)'(.|" . PHP_EOL
                . ")*?'(\s*),/",
                $callback,
                $config);
        $result = file_put_contents($config_file, $config);
        return $result;
    }

    /**
     * 一对一with关联数据一层返回
     *
     * @param array $data 需筛选结果集
     * @param array $endData 最终返回数据格式下标数组
     * @param array $screenData with关联筛选返回数据格式拼接集
     * @param array $manyData 数组返回字段集
     * @param array $defaults 默认值数组
     * @param array $callback 闭包方法
     * @param int $type 执行数据是否是一条  0 否  1 是
     * @param int $notFound 是否空字符和null即删除  0 否  1 是
     * @return array
     */
    public static function partWith(
        $data = [],
        array $endData = [],
        array $screenData = [],
        array $manyData = [],
        $defaults = [],
        $callback = [],
        $type = 0,
        $notFound = 0
    )
    {
        if (!is_array($data)) {
            return $data;
        }
        if ($type) {
            //循环需要拆分数组   $screenIndex   with关联筛选字段   $screenValue  返回别名
            foreach ($screenData as $screenIndex => $screenValue) {
                if (array_key_exists($screenIndex, $data)) {
                    $data = self::withSplit($screenValue, $data[$screenIndex], $data,$endData);
                }
            }
            /**
             * 循环一对多/数组型返回数据
             * 判断当前扁平化数据是否存在一对多下标
             * 存在及递归访问数据扁平化
             * $manyData 多条数据
             * $manyDataValue[0] 最终扁平化结果
             * $manyDataValue[1] 数据筛选数组
             */
            foreach ($manyData as $manyDataItem => $manyDataValue) {
                if (array_key_exists($manyDataItem, $data)) {
                    if (isset($manyDataValue[1][0])) {
                        $data[$manyDataItem] = self::partWith($data[$manyDataItem], $manyDataValue[0], ...$manyDataValue[1]);
                    } else {
                        $data[$manyDataItem] = self::partWith($data[$manyDataItem], $manyDataValue[0], isset($manyDataValue[1]) ? $manyDataValue[1] : []);
                    }
                }
            }
            $data = self::endDataSplicing($endData, $data, $defaults, $notFound);
            //处理方法
            if (count($callback)) {
                $data = self::endDataFunction($data, $callback);
            }
        } else {
            foreach ($data as $dataIndex => $dataValue) {
                //循环需要拆分数组   $screenIndex   with关联筛选字段   $screenValue  返回别名
                foreach ($screenData as $screenIndex => $screenValue) {
                    //判断当前筛选下标是否在一级数组
                    if (array_key_exists($screenIndex, $dataValue)) {
                        //当前一级数组筛选字段，当前筛选下标数组数据，当前一级数组全部数据，
                        $dataValue = self::withSplit($screenValue, $dataValue[$screenIndex], $dataValue,$endData);
                    }
                }
                /**
                 * 循环一对多/数组型返回数据
                 * 判断当前扁平化数据是否存在一对多下标
                 * 存在及递归访问数据扁平化
                 * $manyData 多条数据
                 * $manyDataValue[0] 最终扁平化结果
                 * $manyDataValue[1] 数据筛选数组
                 */
                foreach ($manyData as $manyDataItem => $manyDataValue) {
                    if (array_key_exists($manyDataItem, $dataValue)) {
                        if (isset($manyDataValue[1][0])) {
                            $dataValue[$manyDataItem] = self::partWith($dataValue[$manyDataItem], $manyDataValue[0], ...$manyDataValue[1]);
                        } else {
                            $dataValue[$manyDataItem] = self::partWith($dataValue[$manyDataItem], $manyDataValue[0], isset($manyDataValue[1]) ? $manyDataValue[1] : []);
                        }
                    }
                }
                $endArr = self::endDataSplicing($endData, $dataValue, $defaults, $notFound);
                //处理方法
                if (count($callback)) {
                    $endArr = self::endDataFunction($endArr, $callback);
                }
                $data[$dataIndex] = $endArr;
            }
        }
        return $data;
    }

    /**
     * with数据拼接第一层
     * @param array $withName with关联字段更改集
     * @param array $withData 当前with关联结果集
     * @param array $endData 最终结果集
     * @param array $endScreen 筛选结果集
     * @return mixed
     */
    public static function withSplit($withName, $withData, $endData, &$endScreen)
    {
        //循环当前一维数组筛选返回字段数组
        foreach ($withName as $index => $value) {
            //当前数据下是否有当前筛选的下标参数，并且当前数据下筛选的下标数据是数组
            if (isset($withData[$index]) && is_array($withData[$index])) {
                //当前需筛返回字段是否是数组
                if (is_array($value)) {
                    //递归重复，将最终返回结果字段添加到最外层
                    $endData = self::withSplit($value, $withData[$index], $endData,$endScreen);
                } else {
                    //当前数据存在
                    if ($withData) {
                        //将当前筛选下标的数据直接放在数据集中
                        $endData[$value] = $withData[$index];
                        $endScreen[] = $value;
                    }
                }
            } else {
                /**
                 * 默认参数别名返回
                 * 类型整型不修改参数名，获取新下标
                 */
                $arrIndex = $index;
                if (is_int($index)) {
                    $arrIndex = $value;
                }
                $endScreen[] = $value;
                if ($withData && isset($withData[$arrIndex])) {
                    //将当前筛选下标的数据直接放在数据集中
                    $endData[$value] = $withData[$arrIndex];
                }
            }
        }
        return $endData;
    }

    /**
     * 最终返回数组拼接
     *
     * @param array $endData 最终返回数组下标数组
     * @param array $data 需筛选结果集
     * @param array $defaults 默认值数组
     * @param int $notFound 是否空字符和null及删除  0 否  1 是
     * @return array
     */
    public static function endDataSplicing($endData, $data, $defaults, $notFound)
    {
        $arr = [];
        //是否存在最终返回数据下标自定义
        foreach ($endData as $endDataKey => $endDataValue) {
            /**
             * 默认参数别名返回
             * 类型整型不修改参数名，获取新下标
             */
            $arrIndex = $endDataKey;
            if (is_int($endDataKey)) {
                $arrIndex = $endDataValue;
            }
            //初步定义
            $arr[$endDataValue] = '';
            //是否存在筛选集
            if (array_key_exists($arrIndex, $data)) {
                $arr[$endDataValue] = $data[$arrIndex];
            }
            //最终数据是否存在,不存在检测是否赋默认值，有取默认值，无则直接返回
            if (array_key_exists($endDataValue, $defaults)){
                if (empty($arr[$endDataValue]) && ($arr[$endDataValue] !== 0) && ($arr[$endDataValue] !== false)){
                    $arr[$endDataValue] = $defaults[$endDataValue];
                }
            }
            //是否预设默认值,不为真及删除(是否为空字符,null,0以及空数组)
            if ($notFound){
                if (!array_key_exists($endDataValue, $defaults)){
                    if ($arr[$endDataValue] === "" || $arr[$endDataValue] === 0 || $arr[$endDataValue] === null || !count($arr[$endDataValue])) {
                        unset($arr[$endDataValue]);
                    }
                }
            }
        }
        return $arr;
    }

    /**
     * 最终返回数据处理
     * @param array $endData 最终结果数据
     * @param array $functionArr 处理数组
     * @return mixed
     */
    public static function endDataFunction($endData, $functionArr)
    {
        foreach ($functionArr as $functionArrKey => $functionArrValue) {
            //自定义参数
            $customArr = isset($functionArrValue[1]) ? $functionArrValue[1] : [];
            //返回
            $endData[$functionArrKey] = call_user_func($functionArrValue[0], $endData, $customArr);
        }
        return $endData;
    }

    /**
     * 手机号号码隐藏中间四位
     *
     * @param $phone
     *
     * @return null|string|string[]
     */
    public static function hidTel($phone)
    {
        $IsWhat = preg_match('/(0[0-9]{2,3}[\-]?[2-9][0-9]{6,7}[\-]?[0-9]?)/i',
            $phone); //固定电话
        if ($IsWhat == 1) {
            return preg_replace('/(0[0-9]{2,3}[\-]?[2-9])[0-9]{3,4}([0-9]{3}[\-]?[0-9]?)/i',
                '$1****$2', $phone);
        } else {
            return preg_replace('/(1[0-9]{1}[0-9])[0-9]{4}([0-9]{4})/i',
                '$1****$2', $phone);
        }
    }

    /**
     * 根据经度维度计算距离
     *
     * @return mixed
     *
     */
    public static function getDistance($lat1, $lng1, $lat2, $lng2)
    {
        $earthRadius = 6367000; //单位M
        $lat1 = ($lat1 * pi()) / 180;
        $lng1 = ($lng1 * pi()) / 180;
        $lat2 = ($lat2 * pi()) / 180;
        $lng2 = ($lng2 * pi()) / 180;
        $calcLongitude = $lng2 - $lng1;
        $calcLatitude = $lat2 - $lat1;
        $stepOne = pow(sin($calcLatitude / 2), 2) + cos($lat1) * cos($lat2)
            * pow(sin($calcLongitude
                / 2), 2);
        $stepTwo = 2 * asin(min(1, sqrt($stepOne)));
        $calculatedDistance = $earthRadius * $stepTwo;
        return round($calculatedDistance);
    }

    /**
     * 秒转换 时分秒
     *
     * @param string $times 时间戳/秒
     * @param string $spitStr 分隔符
     *
     * @return string
     */
    public static function secToTime($times, $spitStr = ':')
    {
        $result = "00{$spitStr}00{$spitStr}00";
        if ($times > 0) {
            $hour = floor($times / 3600);
            $minute = floor(($times - 3600 * $hour) / 60);
            $second = floor((($times - 3600 * $hour) - 60 * $minute) % 60);
            $result = $hour . $spitStr . $minute . $spitStr . $second;
        }
        return $result;
    }

    /**
     * 概率计算
     *
     * @param $proArr
     *
     * @return int|string
     */
    public static function get_rand($proArr)
    {
        $result = '';
        //概率数组的总概率精度
        $proSum = array_sum($proArr);
        //概率数组循环
        foreach ($proArr as $key => $proCur) {
            $randNum = mt_rand(1, $proSum);
            if ($randNum <= $proCur) {
                $result = $key;
                break;
            } else {
                $proSum -= $proCur;
            }
        }
        unset ($proArr);
        return $result;
    }

    /**
     * 验证字符串
     * @param string $string 字符串
     * @param int $type 测试类型
     * @return bool
     */
    public static function checkString($string, $type = 1)
    {
        switch ($type) {
            case 1://验证汉字，字母，数字
                if (preg_match("/^[\u{4E00}-\u{9FA5}A-Za-z0-9]+$/u", $string)) {
                    return true;
                }
                break;
        }
        return false;
    }

    /**
     * 时间推算
     * @param int $time 时间戳
     * @return false|string
     */
    public static function reckonTime($time)
    {
        $rtime = date("Y-m-d", $time);
        $time = time() - $time;
        if ($time < 60) {
            $str = '刚刚';
        } elseif ($time < 60 * 60) {
            $min = floor($time / 60);
            $str = $min . '分钟前';
        } elseif ($time < 60 * 60 * 24) {
            $h = floor($time / (60 * 60));
            $str = $h . '小时前';
        } elseif ($time < 60 * 60 * 24 * 3) {
            $d = floor($time / (60 * 60 * 24));
            if ($d == 1)
                $str = '昨天';
            else
                $str = '前天';
        } else {
            $str = $rtime;
        }
        return $str;
    }

    /**
     * 字符转换
     * @param $str
     * @return bool|false|string|string[]|null
     */
    public static function strToUtf8($str)
    {
        $encode = mb_detect_encoding($str, array("ASCII", 'UTF-8', "GB2312", "GBK", 'BIG5'));
        if ($encode == 'UTF-8') {
            return $str;
        } else {
            return mb_convert_encoding($str, 'UTF-8', $encode);
        }
    }

    /**
     * 网络请求
     * @param $http_type
     * @param $method
     * @param $url
     * @param $data
     * @return bool|false|string
     */
    public static function http_req($http_type, $method, $url, $data)
    {
        $ch = curl_init();
        if (strstr($http_type, 'https')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
        if ($method == 'post') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } else {
            $dataString = '';
            $start = 0;
            foreach ($data as $key => $value) {
                if ($start) {
                    $dataString .= '&' . $key . "=" . $value;
                } else {
                    $dataString .= $key . "=" . $value;
                }
                $start = 1;
            }
            $url = $url . '?' . $dataString;
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 100000);//瓒呮椂鏃堕棿
        try {
            $ret = curl_exec($ch);
        } catch (\Exception $e) {
            curl_close($ch);
            return json_encode(['ret' => 0, 'msg' => 'failure']);
        }
        curl_close($ch);
        return $ret;
    }

    /**
     * Laravel直接生成模型类
     * @param array $sqlInfo 数据库信息
     * @param array $onlyArray 只处理表
     * @param array $fileGroups 表名分组文件夹名称
     * @param array $notArray 不需要生成模型类的完整表名
     * @param string $useBase 继承Base模型
     * @param string $extends 继承模型 extends Base
     * @param string $modelPath 模型文件地址 默认 ../app/Models/
     * @return string
     */
    public static function makeModel($sqlInfo, $onlyArray = [], $fileGroups = [], $notArray = [], $useBase = 'App\Models\Base', $extends = 'extends Base', $modelPath = '../app/Models/')
    {
        try {
            //链接数据库
            $host = $sqlInfo["host"];//链接信息
            $user = $sqlInfo["user"];//用户名
            $password = $sqlInfo["password"];//密码
            $database = $sqlInfo["database"];//数据库
            $port = isset($sqlInfo["port"]) ? $sqlInfo["port"] : 3360;//端口号
            $socket = isset($sqlInfo["socket"]) ? $sqlInfo["socket"] : "";//端口号
            $connect = mysqli_connect($host, $user, $password, $database, $port, $socket);
            if (!$connect) {
                throw new \Exception("数据库链接失败！");
            }
            //执行查询
            $data = mysqli_query($connect, "show table status");
            if (!$data) {
                throw new \Exception("数据库查询失败！");
            }
            //获取所有查询数据
            $tables = mysqli_fetch_all($data, MYSQLI_ASSOC);
            if (!$tables) {
                throw new \Exception("数据库获取失败失败！");
            }
            $prefix = $sqlInfo["prefix"];//数据库表前缀
            $prefixNum = strlen($prefix);//数据库表前缀长度
            foreach ($tables as $table) {
                //获取当前表名
                $tableName = substr($table['Name'], $prefixNum);
                //只创建指定表模型
                if (count($onlyArray) && !in_array($prefix . $tableName, $onlyArray)) {
                    continue;
                }
                //是否存在不构造模型数组
                if (in_array($prefix . $tableName, $notArray)) {
                    continue;
                }
                $tableDown = explode('_', $tableName);
                //获取当前表首名称
                $firstName = $tableDown[0];
                //执行驼峰命名
                $bigTableName = "";
                foreach ($tableDown as $tableDownValue) {
                    $bigTableName .= ucfirst($tableDownValue);
                }
                //当前是否在表名分组
                if (in_array($firstName, $fileGroups)) {
                    //拼接文件夹的命名空间
                    $filesName = ucfirst($firstName);
                    if (!is_dir($modelPath . $filesName)) {
                        mkdir($modelPath . $filesName, 0777, true);
                    }
                    $namespace = '\\' . $filesName;
                    $modelName = $modelPath . $filesName . '/' . $bigTableName;
                } else {
                    $namespace = '';
                    $modelName = $modelPath . $bigTableName;
                }
                //生成模型文件
                $fileInfo = '<?php
        
namespace App\Models'.$namespace.';
        
use Illuminate\Database\Eloquent\Model;
        
class ' . $bigTableName . ' extends Model
{
    //
}';
                file_put_contents($modelName . ".php", $fileInfo);
                //打开创建的文件
                $modelPathFile = $modelName . '.php';
                $fileInfo = file_get_contents($modelPathFile);
                //替换模型定义和备注
                $fileInfo = str_replace('//', '/**
     * ' . $table['Comment'] . '
     * @var string
     */ 
    protected $table="' . $tableName . '";', $fileInfo);
                //是否存在继承基础模型
                if ($useBase) {
                    //更改模型继承
                    $fileInfo = str_replace('extends Model', $extends, $fileInfo);
                    //更改引入继承模型
                    $fileInfo = str_replace('Illuminate\Database\Eloquent\Model', $useBase, $fileInfo);
                }
                file_put_contents($modelPathFile, $fileInfo);
            }
            return "执行成功";
        } catch (\Exception $r) {
            return $r->getMessage();
        }
    }
}