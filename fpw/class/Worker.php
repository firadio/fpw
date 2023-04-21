<?php

class Worker {

    protected $app;
    private $bIsThinkPHP = false;
    public $sFramework = 'tp';
    public $sAutoload = '/tp/vendor/autoload.php';
    public $sServerUrl = 'http://127.0.0.1';
    public $iTimeout = 3600;
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
        $this->initMimeTypeMap(dirname(dirname(__DIR__)) . '/mime.types');
        $this->ch = curl_init();
    }

    public function init() {
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
        if (!$this->sProxyUrl) {
            return;
        }
        $sUrlFull = $this->sProxyUrl . $oReq->sUrl;
        $mHeader = $oReq->mHeader;
        $mHeader['x-forwarded-for'] = $oReq->sUserIP;
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

        $sFpwRequest = 'sign';

        $fAddToCurlMultiByFpw = function ($mReqHeader, $sReqBody) use ($multi_ch, $sh_fpw, &$sFpwRequest) {
            if ($sFpwRequest) {
                $mReqHeader['fpw-request'] = $sFpwRequest;
            }
            $ch = $this->getNewCurl('POST', $this->sServerUrl, $mReqHeader, $sReqBody);
            $custom_data = array();
            $custom_data['type'] = 'fpw';
            curl_setopt($ch, CURLOPT_PRIVATE, json_encode($custom_data));
            curl_setopt($ch, CURLOPT_USERAGENT, 'php-worker-v1');
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->sCookieFile);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->sCookieFile);
            curl_setopt($ch, CURLOPT_SHARE, $sh_fpw);
            curl_multi_add_handle($multi_ch, $ch);
        };

        $fGetMsgByCurlInfo = function ($result, $msg) {
            if ($result === CURLE_COULDNT_CONNECT) {
                return '反向代理连不到源服务器';
            }
            return json_encode(array('result' => $result, 'msg' => $msg));
        };

        $curCount = 0;
        $fNewFpwCurlMulti = function ($limit = 1) use ($fAddToCurlMultiByFpw, &$curCount) {
            $count = $this->iMaxConcurrency - $curCount;
            if ($count <= 0) {
                return;
            }
            if ($count > $limit) {
                $count = $limit;
            }
            $time = date('H:i:s');
            echo "\r\n{$time} [Connect";
            for ($i = 1; $i <= $count; $i++) {
                $mReqHeader = array();
                $this->setHeaderByFpwInfo($mReqHeader);
                $fAddToCurlMultiByFpw($mReqHeader, '');
                $curCount++;
                echo ",{$curCount}";
            }
            echo "]";
            return true;
        };

        $fNewFpwCurlMulti(1);

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
            while (!$bStop && $info = curl_multi_info_read($multi_ch)) {
                $iReadCount++;
                $mReqHeader = array();
                $this->setHeaderByFpwInfo($mReqHeader);
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
                    $aResBody[] = $fGetMsgByCurlInfo($info['result'], $info['msg']);
                    $fAddToCurlMultiByFpw($mReqHeader, implode('', $aResBody));
                    continue;
                }
                if ($custom_data['type'] === 'fpw') {
                    if ($info['result'] === CURLE_OK) {
                        if ($iStatusCode == 200) {
                            // 成功连接服务器后就不用发送请求标记了
                            $sFpwRequest = '';
                        } else {
                            // 假如服务器拒绝连接就退出程序
                            $bStop = true;
                        }
                        if (!isset($mResHeader['fpw-rid'])) {
                            // 来自FPW服务器的消息
                            $mResBody = json_decode($sResBody, true);
                            if (is_array($mResBody)) {
                                echo $mResBody['msg'];
                            } else {
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
                            curl_setopt($ch, CURLOPT_PRIVATE, json_encode($custom_data));
                            curl_multi_add_handle($multi_ch, $ch);
                            continue;
                        }
                        // 将程序的处理结果通过FPW服务器转发到浏览器
                        list($iStatusCode, $mFpwHeader, $sReqBody) = $o;
                        $mReqHeader['fpw-rid'] = $mResHeader['fpw-rid'];
                        $mReqHeader['fpw-status'] = $iStatusCode;
                        $mReqHeader['fpw-header'] = json_encode($mFpwHeader);
                        $fAddToCurlMultiByFpw($mReqHeader, $sReqBody);
                        continue;
                    }
                    // 失败了，就要重新加入并发，确保并发数不会减少
                    $curCount--;
                    continue;
                }
            }
            if (!$bStop && $iReadCount) {
                $fNewFpwCurlMulti(10);
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
            $mHeader[$sKey] = $sValue;
        }
        return $mHeader;
    }

    private function header_mtoa($mHeader) {
        // 将Hastable类型转为Array
        $aHeader = array();
        foreach($mHeader as $sKey => $sValue) {
            $aHeader[] = $sKey . ': ' . $sValue;
        }
        return $aHeader;
    }

    private function getBrowserRequest($mReqHeader, $sReqBody) {
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
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->iTimeout);
        curl_setopt($ch, CURLOPT_PIPEWAIT, true);
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, true);
        curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 120);
        curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 60);
        //curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS);
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
            if (!isset($mReqHeader['Content-Type'])) {
                $mReqHeader['Content-Type'] = 'application/octet-stream';
            }
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->header_mtoa($mReqHeader));
        return $ch;
    }

    private function httpRequestByCurl($sMethod, $sUrl, $mReqHeader, $sReqBody) {
        // 采用 curl 写的 http请求
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
            if (!isset($mReqHeader['Content-Type'])) {
                $mReqHeader['Content-Type'] = 'application/octet-stream';
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
