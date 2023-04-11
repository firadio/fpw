<?php

require_once __DIR__ . '/class/Worker.php';

$oWorker = new Worker();
$oWorker->sFramework = getenv('FPW_FRAMEWORK');
$oWorker->sAutoload = getenv('FPW_AUTOLOAD');
$oWorker->iTimeout = 3600;
$oWorker->bIsDebug = false;
$oWorker->sServerUrl = getenv('FPW_URL');
$oWorker->fpwInfo['host'] = getenv('FPW_HOST');
$oWorker->fpwInfo['token'] = getenv('FPW_TOKEN');
$oWorker->sProxyUrl = getenv('FPW_PROXY_URL');
$oWorker->sWwwrootDir = getenv('FPW_PUBLIC');
$oWorker->aDefaDocument = array('index.htm', 'index.html');
$oWorker->init();

echo "Firadio PHP Worker is Running for {$oWorker->fpwInfo['host']}\r\n";

$oWorker->run(function ($sFpwUIP, $sFpwMethod, $sFpwUrl, $mReqHeader, $sReqBody, $sReqPath, $mReqQuery, $mReqForm) use ($oWorker) {
    $time = date('H:i:s');
    echo "{$time} {$sFpwUIP} [{$sFpwMethod}] {$sFpwUrl}\r\n";
    $aResByProxy = $oWorker->Proxy($sFpwUIP, $sFpwMethod, $sFpwUrl, $mReqHeader, $sReqBody);
    if ($aResByProxy) {
        // 如果反向代理成功，直接返回
        return $aResByProxy;
    }
    if ($sFpwMethod === 'GET') {
        $aResByFile = $oWorker->FileServer($sReqPath);
        if ($aResByFile) {
            // 如果文件加载成功，直接返回
            return $aResByFile;
        }
    }
    $aResByFw = $oWorker->FrameworkWorker($sFpwUIP, $sFpwMethod, $sFpwUrl, $sReqPath, $mReqHeader, $sReqBody);
    if ($aResByFw) {
        // 如果框架访问成功，直接返回
        return $aResByFw;
    }
    // 都不成功的情况下，提示404错误
    $mFpwHeader = array();
    $mFpwHeader['content-type'] = 'text/html;charset=utf-8';
    $sResBody = 'File Not Found: ' . $sReqPath;
    return array(404, $mFpwHeader, $sResBody);
});
