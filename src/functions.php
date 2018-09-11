<?php
declare(strict_types=1);

namespace icePHP;

/**
 * 设置本次会话的日志的标题,入栈
 * @param $title string 标题
 */
function tableLog(string $title): void
{
    TableLog::title($title);
}