<?php

require_once __DIR__ . '/class/Request.php';
require_once __DIR__ . '/class/Worker.php';

$oWorker = new Worker();
$oWorker->sFramework = getenv('FPW_FRAMEWORK');
$oWorker->sAutoload = getenv('FPW_AUTOLOAD');
$oWorker->sServerUrl = getenv('FPW_URL');
$oWorker->sServerIP = getenv('FPW_IP');
$oWorker->fpwInfo['host'] = getenv('FPW_HOST');
$oWorker->fpwInfo['token'] = getenv('FPW_TOKEN');
$oWorker->sProxyUrl = getenv('FPW_PROXY_URL');
$oWorker->sWwwrootDir = getenv('FPW_PUBLIC');
$oWorker->sCookieFile = getenv('FPW_COOKIE');
$oWorker->aDefaDocument = array('index.htm', 'index.html');
$oWorker->init();

echo "Firadio PHP Worker v2.0";

$oWorker->run(function ($oReq) use ($oWorker) {
    $time = date('H:i:s');
    echo "\r\n{$time} {$oReq->sUserIP} [{$oReq->sMethod}] {$oReq->mHeader['host']}{$oReq->sUrl}";
    $aResByProxy = $oWorker->Proxy($oReq);
    if ($aResByProxy) {
        // 如果反向代理成功，直接返回
        if (isset($aResByProxy[0])) {
            // 显示状态码
            echo " [{$aResByProxy[0]}]";
        }
        return $aResByProxy;
    }
    if ($oReq->sMethod === 'GET') {
        $aResByFile = $oWorker->FileServer($oReq);
        if ($aResByFile) {
            // 如果文件加载成功，直接返回
            return $aResByFile;
        }
    }
    $aResByFw = $oWorker->FrameworkWorker($oReq);
    if ($aResByFw) {
        // 如果框架访问成功，直接返回
        return $aResByFw;
    }
    // 都不成功的情况下，提示404错误
    $mFpwHeader = array();
    $mFpwHeader['content-type'] = 'text/html;charset=utf-8';
    $sResBody = 'File Not Found: ' . $oReq->sUrl;
    return array(404, $mFpwHeader, $sResBody);
});
