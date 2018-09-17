<?php
declare(strict_types=1);

namespace icePHP;

/**
 * 使用数据库表进行系统日志
 */
class TableLog extends Model
{
    //临时禁止日志,只对操作日志起作用
    static private $temporaryDisable = false;

    /**
     * 临时关闭日志,请开发人员别忘记主动恢复
     */
    static public function stop(): void
    {
        self::$temporaryDisable = true;
    }

    /**
     * 恢复日志记录
     */
    static public function resume(): void
    {
        self::$temporaryDisable = false;
    }

    //当前请求的MCA
    private static $module, $controller, $action;

    //当前请求的客户端IP
    private static $clientIp;

    //当前请求是否需要记录请求体
    private static $needBody;

    //当前后台管理员编号及名称
    private static $adminId, $adminName;

    /**
     * 初始化表日志系统,在派发前进行
     * @param $module string 当前模块名称
     * @param $controller string 当前控制器名称
     * @param $action string 当前动作名称
     * @param $clientIp string 当前请求客户端IP
     * @param $needBody bool 是否需要记录请求体
     * @param $adminId int  当前管理员编号
     * @param $adminName string 当前管理员名称
     */
    public static function init(string $module, string $controller, string $action, string $clientIp, bool $needBody, ? int $adminId = 0, ?string $adminName = ''): void
    {
        self::$module = $module;
        self::$controller = $controller;
        self::$action = $action;
        self::$clientIp = $clientIp;
        self::$needBody = $needBody;

        self::$adminId = $adminId;
        self::$adminName = $adminName;
    }

    /**
     * 当前控制器实例
     * @var Controller
     */
    private static $controllerInstance;

    /**
     * 记录当前控制器实例, 在框架核心 派发并创建控制器实例 后进行
     * @param Controller $instance
     */
    public static function setControllerInstance(Controller $instance): void
    {
        self::$controllerInstance = $instance;
    }

    /**
     * 用于记录本次会话的日志的标题栈,最初应该存入本次会话的功能说明
     * @var string[]
     */
    private static $logTitle = [];

    /**
     * 设置本次会话的日志的标题,入栈
     * @param $title string 标题
     */
    public static function title(string $title): void
    {
        self::$logTitle[] = $title;
    }

    /**
     * 后退一次会话日志标题,出栈
     */
    public static function titleEnd(): void
    {
        //不允许清空栈
        if (count(self::$logTitle) > 1) {
            array_pop(self::$logTitle);
        }
    }

    /**
     * 自动记录数据库操作日志
     * @param $table string 操作的表名
     * @param $operation string 操作名称
     * @param $data mixed 要记录的数据
     * @throws MysqlException
     */
    public static function db(string $table, string $operation, $data): void
    {
        //如果已经临时禁止了
        if (self::$temporaryDisable) {
            return;
        }

        //查看记录的日志标题栈
        if (!empty(self::$logTitle)) {
            //取最右一个
            $title = self::$logTitle[count(self::$logTitle) - 1];
        } else {
            //从程序注释中获取标题
            $title = self::reflectTitle();
            self::$logTitle[] = $title;
        }

        self::operation($title, $table, $operation, $data);
    }

    /**
     * 通过反射从程序注释中获取标题
     * @return string
     */
    private static function reflectTitle(): string
    {
        //反射Action
        try {
            $method = new \ReflectionMethod(self::$controllerInstance, self::$action);
        } catch (\Exception $e) {
            return 'Reflect Fail';
        }

        //取方法注释
        $comment = $method->getDocComment();

        //默认标题
        $title = self::$module . '::' . self::$controller . '::' . self::$action;

        //从注释中取标题
        if ($comment)  //可能根本就没有.
            foreach (explode("\r", $comment) as $line) {
                $line = trim($line);
                if (left($line, 2) == '/*') continue;
                $line = trim(trim($line, '*'));
                if (left($line, 1) == '@' or $line == '/') continue;
                return $line;
            }

        //返回默认标题
        return $title;
    }

    /**
     * 操作日志
     * @param $title string 操作标题
     * @param $table string 操作的表名
     * @param $operation string 操作类型
     * @param mixed $data 操作数据
     * @throws MysqlException
     */
    public static function operation(string $title, string $table, string $operation, $data): void
    {
        table(self::T_OPERATION)->insert([
            'adminId' => self::$adminId,
            'adminName' => self::$adminName,
            'title' => $title,
            'table' => $table,
            'operation' => $operation,
            'logRequestId' => self::$logRequestId,
            'data' => json($data),
            'm' => self::$module
        ]);
    }

    /**
     * 记录调试日志
     * @param $title string 日志标题
     * @param $info mixed 日志内容
     * @throws MysqlException
     */
    public static function debug(string $title, $info): void
    {
        table(self::T_DEBUG)->insert([
            'adminId' => self::$adminId,
            'adminName' => self::$adminName,
            'requestId' => self::$logRequestId,
            'params' => json($_REQUEST),
            'title' => $title,
            'content' => json($info),
            'm' => self::$module
        ]);
    }

    //系统 请求日志的编号
    static private $logRequestId;

    /**
     * 获取当前请求日志的编号
     * @return mixed
     */
    static public function getLogRequestId(): int
    {
        //有时,指定了不记录请求日志,导致此处为空
        return intval(self::$logRequestId);
    }

    //请求开始时间
    static private $logRequestBegin;

    //常用的日志表名称
    const T_REQUEST = 'logRequest'; //请求日志
    const T_OPERATION = 'logOperation'; //操作日志
    const T_DEBUG = 'logDebug'; //调试日志

    /**
     * 记录系统 请求日志
     * @param $end bool 是否结束
     * @throws MysqlException
     */
    public static function request(bool $end = false): void
    {
        //记录结束日志
        if ($end) {
            self::requestEnd();
            return;
        }

        //查看是否在免记录名单中
        $noLogs = configDefault([], 'log', 'noLogRequest');
        if (in_array([self::$module, self::$controller, self::$action], $noLogs)) {
            return;
        }

        //记录请求开始时间
        self::$logRequestBegin = microtime(true);

        //记录请求日志
        self::$logRequestId = table(self::T_REQUEST)->insert([
            'adminId' => self::$adminId,  //后台管理员编号
            'adminName' => self::$adminName, //后台管理员名称

            //当前请求的MCA
            'm' => self::$module,
            'c' => self::$controller,
            'a' => self::$action,

            'request' => json($_REQUEST),//当前请求参数
            'ip' => self::$clientIp, //当前请求的客户端IP
            'cookie' => isset($_COOKIE) ? json($_COOKIE) : '',
            'session' => isset($_SESSION) ? json($_SESSION) : '',

            //当前的请求体
            'body' => self::$needBody ? file_get_contents('php://input') : ''
        ]);

        //通知文件日志对象,当前表记录的编号
        FileLog::instance()->setLogRequestId(self::$logRequestId);
    }

    /**
     * 请求结束时,记录耗费时间
     * @throws MysqlException
     */
    private static function requestEnd(): void
    {
        if (self::$logRequestId) {
            table(self::T_REQUEST)->update([
                'consume' => round((microtime(true) - self::$logRequestBegin) * 1000)
            ], self::$logRequestId);
        }
    }

    /**
     * 创建三个日志表 logRequest,logOperation,logDebug
     * @throws MysqlException
     */
    public static function createTable(): void
    {
        //创建请求日志表
        $tRequest = self::T_REQUEST;
        $sql = "CREATE" . " TABLE IF NOT EXISTS `{$tRequest}` (
           `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '请求的日志',
           `adminId` int(11) DEFAULT NULL COMMENT '后台管理人员id',
           `adminName` varchar(200) DEFAULT NULL COMMENT '后台管理人员姓名',
           `userId` int(11) DEFAULT NULL COMMENT '前台会员编号',
           `userName` varchar(200) DEFAULT NULL COMMENT '前台会员用户名',
           `m` varchar(50) DEFAULT NULL COMMENT '模板',
           `c` varchar(50) DEFAULT NULL COMMENT '控制器',
           `a` varchar(50) DEFAULT NULL COMMENT '方法',
           `request` longtext COMMENT '请求',
           `ip` varchar(50) DEFAULT NULL COMMENT '请求来源IP',
           `cookie` text COMMENT '请求COOKIE',
           `session` text COMMENT '请求SESSION',
           `consume` int(11) DEFAULT NULL COMMENT '用时(毫秒)',
           `body` longtext COMMENT '请求体',
           `created` datetime DEFAULT NULL COMMENT '请求时间',
           `updated` datetime DEFAULT NULL COMMENT '更新时间',
           PRIMARY KEY (`id`),
           KEY `ip` (`ip`),
           KEY `mca_username` (`m`,`c`,`a`,`adminName`(191)),
           KEY `ip_created` (`ip`,`created`)
         ) ENGINE=InnoDB AUTO_INCREMENT=56516 DEFAULT CHARSET=utf8mb4";
        table()->execute($sql);

        //创建操作日志表
        $tOperation = self::T_OPERATION;
        $sql = "CREATE" . " TABLE IF NOT EXISTS `{$tOperation}` (
           `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '操作日志',
           `adminId` int(11) DEFAULT NULL COMMENT '进行此操作的管理员编号',
           `adminName` varchar(200) DEFAULT NULL COMMENT '管理员用户名',
           `userId` int(11) DEFAULT NULL COMMENT '前台会员编号',
           `userName` varchar(200) DEFAULT NULL COMMENT '前台会员用户名',
           `m` varchar(50) DEFAULT NULL COMMENT '模块名称',
           `table` varchar(50) DEFAULT NULL COMMENT '操作的表',
           `title` varchar(200) DEFAULT NULL COMMENT '操作名称',
           `operation` varchar(50) DEFAULT NULL COMMENT '数据库操作类型',
           `logRequestId` int(11) DEFAULT NULL COMMENT '请求参数',
           `data` longtext COMMENT '操作的数据',
           `created` datetime DEFAULT NULL COMMENT '操作时间',
           `updated` datetime DEFAULT NULL COMMENT '更新时间',
           PRIMARY KEY (`id`),
           KEY `title` (`title`(191),`created`),
           KEY `table_operation` (`table`,`operation`,`created`)
         ) ENGINE=InnoDB AUTO_INCREMENT=247695 DEFAULT CHARSET=utf8mb4";
        table()->execute($sql);

        //创建调试信息表
        $tDebug = self::T_DEBUG;
        $sql = "CREATE" . " TABLE IF NOT EXISTS `{$tDebug}` (
           `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '调试日志',
           `adminId` int(11) DEFAULT NULL COMMENT '操作员编号',
           `adminName` varchar(200) DEFAULT NULL COMMENT '操作员姓名',
           `userId` int(11) DEFAULT NULL COMMENT '前台会员编号',
           `userName` varchar(200) DEFAULT NULL COMMENT '前台会员用户名',
           `requestId` int(11) DEFAULT NULL COMMENT '请求日志的编号',
           `m` varchar(50) DEFAULT NULL COMMENT '模块名称',
           `title` varchar(200) DEFAULT NULL COMMENT '日志标题',
           `params` text COMMENT '请求参数',
           `content` longtext COMMENT '调试数据',
           `created` datetime DEFAULT NULL COMMENT '发生时间',
           `updated` datetime DEFAULT NULL COMMENT '修改时间',
           PRIMARY KEY (`id`),
           KEY `title` (`title`,`created`)
         ) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8";
        table()->execute($sql);
    }

    /**
     * 根据数据构造嵌套表格
     * @param $data mixed 数据
     * @return string 表格HTML
     * @deprecated 尚未使用,以后查询日志时,可能使用
     */
    static private function dataTable($data): string
    {
        //如果是字符串或数值
        if (is_string($data) or is_numeric($data)) {
            return $data;
        }

        //如果是对象,转换成数组
        if (is_object($data)) {
            $data = (array)$data;
        }

        //拼接表格
        $ret = '<table>';
        foreach ($data as $key => $row) {
            $ret .= '<tr><td>' . $key . '</td><td>';
            if (is_object($row) or is_array($row)) {
                $row = self::dataTable($row);
            }
            $ret .= $row . '</td></tr>';
        }
        $ret .= '</table>';
        return $ret;
    }


    /**
     * 分页列表操作日志
     * @param $ip string ip
     * @param $type string M
     * @return Result
     * @deprecated 尚未使用,以后查询日志时,可能使用
     * @throws MysqlException
     */
    private static function pageOperation($ip, $type)
    {
        //构造查询条件
        $where = [];
        $where['m ='] = $type;
        if ($ip) {
            $where['logRequestId in'] = self::getRequestIdByIp($ip);
        }

        //50一页
        $page = page(20);
        $page->where(['IP' => $ip]);

        //计算总数
        $t = table(self::T_OPERATION);
        $page->count($t->count($where));

        //返回查询结果
        return $t->select('*', $where, $page->orderby(), $page->limit())
            ->map("adminUser", ['adminId' => 'id'], 'name as adminName')
            ->map("user", ['userId' => 'id'], 'name as userName')
            ->map("logRequest", ['logRequestId' => 'id'], 'ip');
    }

    /**
     * @param $ip string ip
     * @return array
     * @deprecated 尚未使用,以后查询日志时,可能使用
     * @throws MysqlException
     */
    private static function getRequestIdByIp($ip)
    {
        return table(self::T_REQUEST)->col('id', ['ip' => $ip]);
    }

    /**
     * 分页列表所有前台请求
     * @param $belong string 前后台
     * @param $begin string 开始时间
     * @param $end string 结束时间
     * @return Result
     * @deprecated 尚未使用,以后查询日志时,可能使用
     * @throws MysqlException
     */
    private static function pageRequest($belong, $begin, $end)
    {
        //构造查询条件
        $where = [];
        if ($belong) {
            if ($belong == "后台") {
                $where['m ='] = 'admin';
            } else {
                $where['m ='] = '';
            }
        }
        if ($begin) {
            $where['created >='] = $begin;
        }
        if ($end) {
            $where['created <='] = $end;
        }

        //50一页
        $page = page(20);
        $page->where(['belong' => $belong, 'begin' => $begin, 'end' => $end]);
        //计算总数
        $t = table(self::T_REQUEST);
        $page->count($t->count($where));

        //返回查询结果
        return $t->select('*', $where, $page->orderby(), $page->limit());
    }

    /**
     * 获取全部管理员列表,名称排序
     * @return Result
     * @deprecated 尚未使用,以后查询日志时,可能使用
     * @throws MysqlException
     */
    static private function listAdminUsers()
    {
        return table('adminUser')->select('id,name', ['status' => '正常'], 'name asc');
    }

    /**
     * 获取全部管理员列表,名称排序
     * @return Result
     * @deprecated 尚未使用,以后查询日志时,可能使用
     * @throws MysqlException
     */
    static private function listUsers()
    {
        return table('user')->select('id,name', '', 'name asc');
    }
}
