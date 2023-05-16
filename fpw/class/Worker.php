<?php

require_once __DIR__ . '/DnsOverHttps.php';
require_once(__DIR__ . '/CurlConcurrency.php');

class Worker {

    protected $app;

    private $bIsThinkPHP = false;
    private $mDefinedConstants;
    private $oCurlConcurrency;
    private $oDnsOverHttps;
    private $_sServerUrl;

    public $sFramework = 'tp';
    public $sAutoload = '/tp/vendor/autoload.php';
    public $sServerUrl = 'http://127.0.0.1';
    public $sServerIP;
    public $iTimeout;
    public $fpwInfo = array();
    public $bIsDebug = FALSE;
    public $sWwwrootDir = '';
    public $aDefaDocument = array('index.htm', 'index.html');
    public $mMimeType = array();
    public $ch;
    public $sProxyUrl = '';
    public $sCookieFile = 'cookie.txt';
    public $bCurlMulti = true;
    public $iMaxConcurrency = 50; // 最大并发请求数

    public function __construct() {
        $this->oCurlConcurrency = new CurlConcurrency();
        $this->oDnsOverHttps = new DnsOverHttps();
        $this->putDefinedConstants();
        $this->initMimeTypeMap(dirname(dirname(__DIR__)) . '/mime.types');
        $this->ch = curl_init();
    }

    private function getStringValue($value) {
        // 转换为字符串
        if (is_numeric($value)) {
            return (string)$value;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        // 既不是字符串、也不是数字、也不是bool型
        return '';
    }

    private function putDefinedConstants() {
        // 把[常量名]的key-value反转，用于通过[常量值]来获得[常量名]
        $mGroups = get_defined_constants(TRUE);
        $mDefinedConstants = array();
        foreach ($mGroups as $sGroupName => $mGroup) {
            $mGroupOne = array();
            foreach ($mGroup as $ConstantName => $value) {
                $sValue = $this->getStringValue($value);
                if ($sValue === '') {
                    continue;
                }
                if (!isset($mGroupOne[$sValue])) {
                    $mGroupOne[$sValue] = array();
                }
                $mGroupOne[$sValue][] = $ConstantName;
            }
            $mDefinedConstants[$sGroupName] = $mGroupOne;
        }
        $this->mDefinedConstants = $mDefinedConstants;
    }

    private function getConstantNameByValue($sGroupName, $value) {
        // 通过[常量值]来获得[常量名]
        $mGroupOne = $this->mDefinedConstants[$sGroupName];
        return $mGroupOne[$value];
    }

    public function init() {
        // 备份原始服务器地址
        $this->_sServerUrl = $this->sServerUrl;
        $sFramework = strtolower($this->sFramework);
        $this->bIsThinkPHP = $sFramework === strtolower('ThinkPHP') || $sFramework === 'tp';
        if ($this->bIsThinkPHP) {
            $this->ThinkPHPInit();
        }
    }

    private function ThinkPHPInit() {
        $sAutoload = $this->sAutoload;
        if (!is_file($sAutoload)) {
            $this->bIsThinkPHP = false;
            return;
        }
        require_once $sAutoload;
        require_once __DIR__ . '/Cookie.php';
        if (!$this->sWwwrootDir) {
            $this->sWwwrootDir = 'tp/public';
        }
        // 加载ThinkPHP框架
        $this->app = new think\App();
        $this->app->initialize();
        $this->app->bind([
            'think\Cookie' => \think\worker\Cookie::class,
        ]);
    }

    public function FrameworkWorker($oReq) {
        if ($this->bIsThinkPHP) {
            return $this->ThinkPHPWorker($oReq);
        }
    }

    public function ThinkPHPWorker($oReq) {

        $_SERVER['REQUEST_URI'] = $oReq->sUrl;

        // 通过ThinkPHP框架处理用户请求
        $pathinfo = ltrim($oReq->getPath(), '/');

        $request = $this->app->request;
        $request->setPathinfo($pathinfo);
        $request->withInput($oReq->sBody);

        while (ob_get_level() > 1) {
            ob_end_clean();
        }

        ob_start();
        $response = $this->app->http->run();
        $content  = ob_get_clean();

        ob_start();

        $response->send();
        //$this->http->end($response);

        $content .= ob_get_clean() ?: '';

        $iStatusCode = $response->getCode();
        $mFpwHeader = $response->getHeader();

        return array($iStatusCode, $mFpwHeader, $content);
    }

    /**
     * Init mime map.
     *
     * @return void
     * @throws \Exception
     */
    protected function initMimeTypeMap($mime_file) {

        if (!is_file($mime_file)) {
            Worker::log("$mime_file mime.type file not fond");
            return;
        }

        $items = file($mime_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (!is_array($items)) {
            Worker::log("get $mime_file mime.type content fail");
            return;
        }

        foreach ($items as $content) {
            if (preg_match("/\s*(\S+)\s+(\S.+)/", $content, $match)) {
                $mime_type                      = $match[1];
                $workerman_file_extension_var   = $match[2];
                $workerman_file_extension_array = explode(' ', substr($workerman_file_extension_var, 0, -1));
                foreach ($workerman_file_extension_array as $workerman_file_extension) {
                    $this->mMimeType[$workerman_file_extension] = $mime_type;
                }
            }
        }
    }

    private function getFilePath($sWwwrootDir, $aDefaDocument, $sReqPath) {
        // 获取文件路径
        if (!$sWwwrootDir) {
            $sWwwrootDir = 'public';
        }
        $filePath = $sWwwrootDir . $sReqPath;
        if (is_file($filePath)) {
            return $filePath;
        }
        if (is_dir($filePath)) {
            foreach ($aDefaDocument as $sIndexFilename) {
                $sIndexFilePath = $filePath . '/' . $sIndexFilename;
                $bIsFile = is_file($sIndexFilePath);
                if ($bIsFile) {
                    return $sIndexFilePath;
                }
            }
            return;
        }
    }

    public function Proxy($oReq) {
        // FPW v1.0版本的反向代理
        if (!$this->sProxyUrl) {
            return;
        }
        $sUrlFull = $this->sProxyUrl . $oReq->sUrl;
        $mHeader = $oReq->mHeader;
        $mHeader['fpw-uip'] = $oReq->sUserIP;
        if ($this->bCurlMulti) {
            $mRes = array();
            $mRes['proxy'] = array();
            $mRes['proxy']['method'] = $oReq->sMethod;
            $mRes['proxy']['url'] = $sUrlFull;
            $mRes['proxy']['header'] = $mHeader;
            $mRes['proxy']['body'] = $oReq->sBody;
            return $mRes;
        }
        return $this->httpRequestByCurl($oReq->sMethod, $sUrlFull, $mHeader, $oReq->sBody);
    }

    public function FileServer($oReq) {
        // 实现静态文件服务器
        $sReqPath = $oReq->getPath();
        $filepath = $this->getFilePath($this->sWwwrootDir, $this->aDefaDocument, $sReqPath);
        if ($filepath) {
            $ext = pathinfo($filepath, PATHINFO_EXTENSION);
            if (isset($this->mMimeType[$ext])) {
                $mFpwHeader = array();
                $mFpwHeader['content-type'] = $this->mMimeType[$ext];
                $sResBody = file_get_contents($filepath);
                return array(200, $mFpwHeader, $sResBody);
            }
        }
    }

    private function compress_getAllowContentType() {
        // 把需要压缩的内容类型放这里
        $aCTs = array();
        $aCTs[] = 'text/plain';
        $aCTs[] = 'text/html';
        $aCTs[] = 'text/css';
        $aCTs[] = 'text/javascript';
        $aCTs[] = 'application/javascript';
        $aCTs[] = 'application/json';
        $aCTs[] = 'application/xml';
        $aCTs[] = 'application/rss+xml';
        $aCTs[] = 'image/svg+xml';
        $aCTs[] = 'font/woff2';
        return $aCTs;
    }

    private function compress_checkContentType($mHeader) {
        // 检查哪些类型需要压缩
        if (!isset($mHeader['content-type'])) {
            // 未知类型不要压缩
            return false;
        }
        $aContentType = explode(';', $mHeader['content-type']);
        $sCT0 = trim($aContentType[0]);
        $aConfCTs = $this->compress_getAllowContentType();
        return in_array($sCT0, $aConfCTs);
    }

    private function compress($mReqHeader, &$mResHeader, &$sBody) {
        if (isset($mResHeader['content-encoding'])) {
            // 已经压缩过的跳过
            return;
        }
        if (!$this->compress_checkContentType($mResHeader)) {
            // 跳过无需压缩的类型
            return;
        }
        $req_ae = isset($mReqHeader['accept-encoding']) ? $mReqHeader['accept-encoding'] : '';
        if (is_numeric(strpos($req_ae, 'gzip'))) {
            $sBody = gzencode($sBody, 9);
            $mResHeader['content-encoding'] = 'gzip';
        }
        $mResHeader['content-length'] = strlen($sBody);
    }

    private function curl_set_connect_to($ch, $url, $to_ip) {
        if (empty($to_ip)) {
            return;
        }
        $mCurl = parse_url($url);
        if (empty($mCurl['host'])) {
            return;
        }
        $port = '';
        if ($mCurl['scheme'] === 'http') {
            $port = isset($mCurl['port']) ? $mCurl['port'] : 80;
        }
        if ($mCurl['scheme'] === 'https') {
            $port = isset($mCurl['port']) ? $mCurl['port'] : 443;
        }
        if (empty($port)) {
            return;
        }
        $item = $mCurl['host'] . ':' . $port . ':' . $to_ip;
        curl_setopt($ch, CURLOPT_CONNECT_TO, array($item));
    }

    private function build_url($url_parts) {
        $url = array();

        if (isset($url_parts['scheme'])) {
            $url[] = $url_parts['scheme'] . '://';
        }

        if (isset($url_parts['user'])) {
            $userinfo = $url_parts['user'];

            if (isset($url_parts['pass'])) {
                $userinfo .= ':' . $url_parts['pass'];
            }

            $url[] = $userinfo . '@';
        }

        if (isset($url_parts['host'])) {
            $url[] = $url_parts['host'];
        }

        if (isset($url_parts['port'])) {
            $url[] = ':' . $url_parts['port'];
        }

        if (isset($url_parts['path'])) {
            $url[] = $url_parts['path'];
        }

        if (isset($url_parts['query'])) {
            $url[] = '?' . $url_parts['query'];
        }

        if (isset($url_parts['fragment'])) {
            $url[] = '#' . $url_parts['fragment'];
        }

        return implode('', $url);
    }

    private function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
        $exp = floor(log($bytes, 1024));
        $formatted = round($bytes / (1024 ** $exp), $precision) . ' ' . $units[$exp];
        return $formatted;
    }

    private function consoleLog($str) {
        echo "\r\n" . date('H:i:s') . ' ' . $str;
    }

    private function getServerUrlAndIpPort($sOriUrl) {

        $mOriUrl = parse_url($sOriUrl);
        $mHeader = array();
        $mHeader['content-type'] = 'application/octet-stream';
        $mHeader['fpw-request'] = 1;
        $iBodyLength = 1024 * 1024;
        $sReqBody = random_bytes($iBodyLength);
        $mOption = array();
        $mOption['CURLOPT_HEADER'] = true;

        while (1) {

            $this->consoleLog('DNS解析中');

            // 开始解析DNS
            $mRet = $this->oDnsOverHttps->dnsQuery($mOriUrl['host'], 'TXT');
            if (empty($mRet)) {
                $this->consoleLog('解析失败');
                continue;
            }

            $aRdatas = $mRet['rdatas'];
            $mData = json_decode($aRdatas[0], true);
            $iCount = count($mData['hosts']);

            $this->consoleLog("从 {$mRet['server']} 解析出 {$iCount} 个IP");

            if (isset($mData['protocal'])) {
                $mOriUrl['scheme'] = rtrim($mData['protocal'], ':');
            }
            if (isset($mData['server_name'])) {
                $mOriUrl['host'] = $mData['server_name'];
            }
            if (isset($mData['path'])) {
                $mOriUrl['path'] = $mData['path'];
            }
            if (isset($mData['query'])) {
                $mOriUrl['query'] = $mData['query'];
            }
            if (isset($mData['alpn'])) {
                // 协议类型，例如：h1,h2,h3
                $mOption['alpn'] = $mData['alpn'];
            }
            $sNewUrl = $this->build_url($mOriUrl);

            $this->consoleLog("开始测速 {$sNewUrl}");
            $aRequests = array();
            foreach ($mData['hosts'] as $sHost) {
                $mOption['connect_to'] = $sHost;
                $mOption['CURLOPT_PRIVATE'] = $sHost;
                $aRequests[] = array('POST', $sNewUrl, $mHeader, $sReqBody, $mOption);
            }
            // 开始并发测速
            $time_start = microtime(true);
            list($sServerIP, $sContent) = $this->oCurlConcurrency->getData($aRequests);

            if (empty($sServerIP)) {
                $this->consoleLog("没有IP能连上");
                sleep(2);
                continue;
            }

            $time_duration = microtime(true) - $time_start;
            $sSpeed = $this->formatBytes($iBodyLength / $time_duration);
            $this->consoleLog("{$sServerIP} 测速成功，速度 {$sSpeed} 每秒");

            return array($sNewUrl, $sServerIP);
        }
    }

    private function run2($fCallback) {

        // 创建curl多句柄
        $multi_ch = curl_multi_init();

        // 初始化共享句柄
        $sh_fpw = curl_share_init();
        curl_share_setopt($sh_fpw, CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE);
        curl_share_setopt($sh_fpw, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
        curl_share_setopt($sh_fpw, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);

        $sh_proxy = curl_share_init();
        curl_share_setopt($sh_proxy, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
        curl_share_setopt($sh_proxy, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);

        $iFpwRequest = 1;

        $fAddToCurlMultiByFpw = function ($mReqHeader = array(), $sReqBody = '') use ($multi_ch, $sh_fpw, &$iFpwRequest) {

            if ($iFpwRequest >= 1 && $iFpwRequest <= 2) {
                list($this->sServerUrl, $this->sServerIP) = $this->getServerUrlAndIpPort($this->_sServerUrl);
            }

            // 开始设置CURL选项
            if ($iFpwRequest) {
                $mReqHeader['fpw-request'] = $iFpwRequest;
            }
            $mReqHeader['content-type'] = 'application/octet-stream';
            $ch = $this->getNewCurl('POST', $this->sServerUrl, $mReqHeader, $sReqBody);
            $this->curl_set_connect_to($ch, $this->sServerUrl, $this->sServerIP);
            $custom_data = array();
            $custom_data['type'] = 'fpw';
            curl_setopt($ch, CURLOPT_PRIVATE, json_encode($custom_data));
            curl_setopt($ch, CURLOPT_USERAGENT, 'php-worker-v2');
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->sCookieFile);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->sCookieFile);
            curl_setopt($ch, CURLOPT_SHARE, $sh_fpw);
            curl_multi_add_handle($multi_ch, $ch);
        };

        $fGetMsgByCurlInfo = function ($mInfo, $request_type) {
            if ($mInfo['msg'] !== 1) {
                return json_encode($mInfo);
            }
            //var_dump(curl_getinfo($mInfo['handle']));
            $aResult = $this->getConstantNameByValue('curl', $mInfo['result']);
            $sResult = implode(',', $aResult);
            $sReqType = strtoupper($request_type);
            if ($sReqType === 'PROXY') {
                $sReqType = '源';
            }
            if ($mInfo['result'] === CURLE_COULDNT_CONNECT) {
                return "[{$sResult}] 无法连接到 {$sReqType} 服务器";
            }
            return "[{$sResult}] 请求 {$sReqType} 时 " . curl_strerror($mInfo['result']);
        };

        $curCount = 0;
        $fNewFpwCurlMulti = function ($limit = 1, $title = '') use ($fAddToCurlMultiByFpw, &$curCount) {
            $count = $this->iMaxConcurrency - $curCount;
            if ($count <= 0) {
                return;
            }
            if ($count > $limit) {
                $count = $limit;
            }
            $time = date('H:i:s');
            $bIsDisp = $title ? true : false;
            if ($bIsDisp) {
                echo "\r\n{$time} [{$title}]";
            }
            $bIsDisp = false;
            for ($i = 1; $i <= $count; $i++) {
                $fAddToCurlMultiByFpw();
                $curCount++;
                if ($bIsDisp) {
                    echo ",{$curCount}";
                }
            }
            if ($bIsDisp) {
                echo "]";
            }
            return true;
        };

        $fNewFpwCurlMulti(1, '开始连接');

        $bStop = false;

        // 开始处理请求
        do {
            usleep(1);

            curl_multi_select($multi_ch);
            // 执行并发请求
            $status = curl_multi_exec($multi_ch, $active);
            // echo "[$active]";
            // 获取已完成的请求
            $iReadCount = 0;
            $iFailCount = 0;
            $mLastInfo = null;
            while (!$bStop && $info = curl_multi_info_read($multi_ch)) {
                $iReadCount++;
                $mReqHeader = array();
                $sReqBody = '';
                $ch = $info['handle'];
                if ($info['result'] === CURLE_OK) {
                    $curl_data = curl_multi_getcontent($ch);
                    list($iStatusCode, $mResHeader, $sResBody) = $this->getHeaderBodyByCurl($curl_data);
                }
                $custom_data = json_decode(curl_getinfo($ch, CURLINFO_PRIVATE), true);
                curl_multi_remove_handle($multi_ch, $ch);
                curl_close($ch);
                if ($custom_data['type'] === 'proxy') {
                    // 如果是后端服务器的回复
                    // 提取一个保存的请求，并发起fpw回复浏览器
                    $mReqHeader['fpw-rid'] = $custom_data['fpw-rid'];
                    if ($info['result'] === CURLE_OK) {
                        $mReqHeader['fpw-status'] = $iStatusCode;
                        $this->compress($custom_data['mReqHeader'], $mResHeader, $sResBody);
                        if (isset($mResHeader['transfer-encoding'])) {
                            echo '[remove transfer-encoding]';
                            unset($mResHeader['transfer-encoding']);
                        }
                        $mReqHeader['fpw-header'] = json_encode($mResHeader);
                        $fAddToCurlMultiByFpw($mReqHeader, $sResBody);
                        continue;
                    }
                    // 反向代理失败了，发起fpw回复浏览器502-bad-gateway
                    $mReqHeader['fpw-status'] = 502;
                    $mReqHeader['fpw-header'] = array();
                    $mReqHeader['fpw-header'] = call_user_func(function () {
                        $mHeader = array();
                        $mHeader['content-type'] = 'text/html;charset=utf-8';
                        return json_encode($mHeader);
                    });
                    $aResBody = array();
                    $aResBody[] = '<meta charset="utf-8">';
                    $aResBody[] = '<h1>502 Bad Gateway</h1>';
                    $aResBody[] = $fGetMsgByCurlInfo($info, $custom_data['type']);
                    $fAddToCurlMultiByFpw($mReqHeader, implode('', $aResBody));
                    continue;
                }
                if ($custom_data['type'] === 'fpw') {
                    if ($info['result'] === CURLE_OK) {
                        $iFpwStatus = isset($mResHeader['fpw-status']) ? intval($mResHeader['fpw-status']) : 0;
                        if ($iStatusCode == 200 && $iFpwStatus >= 200 && $iFpwStatus <= 299) {
                            // 成功连接服务器后就要将请求标记设成3
                            $iFpwRequest = 3;
                        } else {
                            // 假如服务器拒绝连接就退出程序
                            $bStop = true;
                        }
                        if (!isset($mResHeader['fpw-rid'])) {
                            // 来自FPW服务器的消息
                            $mResBody = json_decode($sResBody, true);
                            if (is_array($mResBody)) {
                                $this->consoleLog('[连接成功]');
                                if (!empty($mResBody['msg'])) {
                                    echo ' -> ';
                                    echo $mResBody['msg'];
                                }
                            } else if ($sResBody) {
                                echo ' -> ';
                                echo $sResBody;
                            }
                            $curCount--;
                            continue;
                        }
                        // 来自浏览器的请求
                        $oReq = new Request();
                        $oReq->sUserIP = $mResHeader['fpw-uip'];
                        $oReq->sMethod = $mResHeader['fpw-method'];
                        $oReq->sUrl = $mResHeader['fpw-url'];
                        $oReq->mHeader = isset($mResHeader['fpw-header']) ? json_decode($mResHeader['fpw-header'], true) : array();
                        $oReq->sBody = $sResBody;
                        $o = $fCallback($oReq);
                        if (isset($o['proxy'])) {
                            // 如果需要反向代理
                            $ch = $this->getNewCurl($o['proxy']['method'], $o['proxy']['url'], $o['proxy']['header'], $o['proxy']['body']);
                            curl_setopt($ch, CURLOPT_SHARE, $sh_proxy);
                            $custom_data['type'] = 'proxy';
                            $custom_data['fpw-rid'] = $mResHeader['fpw-rid'];
                            $custom_data['mReqHeader'] = $oReq->mHeader;
                            curl_setopt($ch, CURLOPT_PRIVATE, json_encode($custom_data));
                            curl_multi_add_handle($multi_ch, $ch);
                            continue;
                        }
                        // 将程序的处理结果通过FPW服务器转发到浏览器
                        list($iStatusCode, $mFpwHeader, $sReqBody) = $o;
                        $mReqHeader['fpw-rid'] = $mResHeader['fpw-rid'];
                        $mReqHeader['fpw-status'] = $iStatusCode;
                        $this->compress($oReq->mHeader, $mFpwHeader, $sReqBody);
                        $mReqHeader['fpw-header'] = json_encode($mFpwHeader);
                        $fAddToCurlMultiByFpw($mReqHeader, $sReqBody);
                        continue;
                    }
                    // 失败了，就要重新加入并发，确保并发数不会减少
                    $curCount--;
                    $iFailCount++;
                    $mLastInfo = $info;
                    continue;
                }
            }
            if (!$bStop && $iReadCount) {
                $iConnCount = 10;
                $sConnMsg = '';
                if (0) {
                    $sConnMsg = '并发建立会话';
                }
                if ($iFailCount) {
                    $iConnCount = 1;
                    $sConnMsg = '重连中';
                    // 掉线了
                    if ($iFpwRequest === 1) {
                        echo ' -> [连接失败]';
                        echo ' -> ' . $fGetMsgByCurlInfo($mLastInfo, 'fpw');
                    } else if ($iFpwRequest === 2) {
                        echo ' -> [重接失败]';
                    } else {
                        $iFpwRequest = 2;
                        echo ' -> [掉线了]';
                    }
                    echo "\r\n";
                    for ($i = 10; $i > 0; $i--) {
                      echo "{$i}.";
                      sleep(1);
                    }
                }
                $fNewFpwCurlMulti($iConnCount, $sConnMsg);
            }
        } while (!$bStop && $status === CURLM_OK);

    }

    private function run1($fCallback) {
        $mResHeader = array();
        $this->setHeaderByFpwInfo($mResHeader);
        $sResBody = '';
        while (true) {
            $req = $this->getBrowserRequest($mResHeader, $sResBody);
            if (!$req) continue;
            if (!isset($req[0]['fpw-rid'])) continue;
            $oReq = new Request();
            $oReq->sUserIP = $req[0]['fpw-uip'];
            $oReq->sMethod = $req[0]['fpw-method'];
            $oReq->sUrl = $req[0]['fpw-url'];
            $oReq->mHeader = isset($req[0]['fpw-header']) ? json_decode($req[0]['fpw-header'], true) : array();
            $oReq->sBody = $req[1];
            list($iStatusCode, $mFpwHeader, $sResBody) = $fCallback($oReq);
            $this->setHeaderByFpwInfo($mResHeader);
            $mResHeader['fpw-rid'] = $req[0]['fpw-rid'];
            $mResHeader['fpw-status'] = $iStatusCode;
            $mResHeader['fpw-header'] = json_encode($mFpwHeader);
        }
    }

    public function run($fCallback) {
        if ($this->bCurlMulti) {
            $this->run2($fCallback);
            return;
        }
        $this->run1($fCallback);
    }

    private function setHeaderByFpwInfo(&$mResHeader) {
        foreach ($this->fpwInfo as $sName => $sValue) {
            $sKey = 'fpw-' . $sName;
            $mResHeader[$sKey] = $sValue;
        }
    }

    private function header_atom($aHeader) {
        // 将Array类型转为Hashtable
        $mHeader = array();
        $sSign = ': ';
        foreach ($aHeader as $sLine) {
            $i = strpos($sLine, $sSign);
            if ($i === FALSE) {
                continue;
            }
            $sKey = strtolower(substr($sLine, 0, $i));
            $sValue = substr($sLine, $i + strlen($sSign));
            if (isset($mHeader[$sKey])) {
                $mHeader[$sKey] = array($mHeader[$sKey]);
                $mHeader[$sKey][] = $sValue;
            } else {
                $mHeader[$sKey] = $sValue;
            }
        }
        return $mHeader;
    }

    private function header_mtoa($mHeader) {
        // 将Hastable类型转为Array
        $aHeader = array();
        foreach($mHeader as $sKey => $aValue) {
            if (is_array($aValue)) {
                foreach ($aValue as $sValue) {
                    $aHeader[] = $sKey . ': ' . $sValue;
                }
            } else {
                $aHeader[] = $sKey . ': ' . $aValue;
            }
        }
        return $aHeader;
    }

    private function getBrowserRequest($mReqHeader, $sReqBody) {
        // 用于FPW v1.0版本
        $aRes = $this->httpRequestByCurl('POST', $this->sServerUrl, $mReqHeader, $sReqBody);
        if ($aRes) {
            list($iStatusCode, $mResHeader, $sResBody) = $aRes;
            return array($mResHeader, $sResBody);
        }
    }

    private function httpRequestByGetFile($sMethod, $sUrl, $mReqHeader, $sReqBody) {
        // 采用 file_get_contents 写的 http请求
        //$mReqHeader['connection'] = 'Keep-Alive';
        $mReqHeader['user-agent'] = 'php-worker-v1';
        $mReqHeader['content-type'] = 'application/octet-stream';
        $mReqHeader['content-length'] = strlen($sReqBody);
        $opts = array();
        $opts['http'] = array();
        $opts['http']['timeout'] = $this->iTimeout;
        $opts['http']['method'] = $sMethod;
        //$opts['http']['protocol_version'] = '1.1';
        $opts['http']['header'] = implode("\r\n", $this->header_mtoa($mReqHeader));
        $opts['http']['content'] = $sReqBody;
        $cxContext = stream_context_create($opts);
        $sBody = @file_get_contents($sUrl, false, $cxContext);
        $aHeader = isset($http_response_header) ? $http_response_header : array();
        $iStatusCode = 200;
        list($mResHeader, $sResBody) = $this->getResponse($sBody, $aHeader);
        return array($iStatusCode, $mResHeader, $sResBody);
    }

    private function getResponse($sBody, $aHeader = array()) {
        $mHeader = $this->header_atom($aHeader);
        if (is_string($sBody)) {
            // 只要有内容就直接返回，不处理错误了。
            return array($mHeader, $sBody);
        }
        $mError = error_get_last();
        if ($mError === null) {
            // 没有错误的话就不处理了。
            echo '.';
            return;
        }
        // 开始处理错误
        if ($mError['type'] === 2 && strpos($mError['message'], 'Failed to open stream: HTTP request failed!') !== false) {
            // 如果是超时则隐藏错误
            echo '*';
            if ($this->bIsDebug) {
                print_r($mHeader);
            }
            return;
        }
        print_r($mError);
        exit;
    }

    private function getNewCurl($sMethod, $sUrl, $mReqHeader, $sReqBody) {
        // 返回一个curl_init（用于FPW v2.0版本）
        $ch = curl_init();
        if ($this->iTimeout) {
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->iTimeout);
        }
        curl_setopt($ch, CURLOPT_PIPEWAIT, true);
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, true);
        curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 120);
        curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 60);
        //curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS);
        curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_URL, $sUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_SSL_SESSIONID_CACHE, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $sMethod);
        if ($sMethod === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $sReqBody);
            $mReqHeader['content-length'] = strlen($sReqBody);
        }
        if (isset($mReqHeader['connection'])) {
            // 前端CDN转发来的connection可能为close，这里设成keep-alive
            $mReqHeader['connection'] = 'keep-alive';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->header_mtoa($mReqHeader));
        return $ch;
    }

    private function httpRequestByCurl($sMethod, $sUrl, $mReqHeader, $sReqBody) {
        // 采用 curl 写的 http请求 (用于FPW v1.0版本)
        curl_setopt($this->ch, CURLOPT_TCP_KEEPALIVE, true);
        curl_setopt($this->ch, CURLOPT_TCP_KEEPIDLE, 120);
        curl_setopt($this->ch, CURLOPT_TCP_KEEPINTVL, 60);
        curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($this->ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($this->ch, CURLOPT_URL, $sUrl);
        curl_setopt($this->ch, CURLOPT_USERAGENT, 'php-worker-v1');
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->ch, CURLOPT_HEADER, true);
        curl_setopt($this->ch, CURLOPT_SSL_SESSIONID_CACHE, true);
        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $sMethod);
        if ($sMethod === 'POST') {
            curl_setopt($this->ch, CURLOPT_POST, true);
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, $sReqBody);
            if (!isset($mReqHeader['content-type'])) {
                $mReqHeader['content-type'] = 'application/octet-stream';
            }
        }
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, $this->header_mtoa($mReqHeader));
        $curl_data = curl_exec($this->ch);
        if ($curl_data === FALSE) {
            $curl_errno = curl_errno($this->ch);
            $curl_error = curl_error($this->ch);
            echo("[Error] {$curl_errno} {$curl_error}");
            return;
        }
        //$iStatusCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
        list($iStatusCode, $mResHeader, $sResBody) = $this->getHeaderBodyByCurl($curl_data);
        return array($iStatusCode, $mResHeader, $sResBody);
    }

    private function getHeaderBodyByCurl($sData) {
        $sDivSign = "\r\n\r\n";
        $iRet = 0;
        while (1) {
            $iRet = strpos($sData, $sDivSign, $iRet);
            if ($iRet === FALSE) {
                return array(array(), $sData);
            }
            $sHeader = substr($sData, 0, $iRet);
            if ($sHeader === 'HTTP/1.1 100 Continue') {
                $iRet += strlen($sDivSign);
                continue;
            }
            break;
        }
        $aHeader = explode("\r\n", $sHeader);
        $sBody = substr($sData, $iRet + strlen($sDivSign));
        $mHeader = $this->header_atom($aHeader);
        $aHeader0 = explode(' ', $aHeader[0]);
        return array($aHeader0[1], $mHeader, $sBody);
    }

}
