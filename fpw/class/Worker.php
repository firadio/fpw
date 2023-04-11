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

    public function ThinkPHPWorker($sFpwUIP, $sFpwMethod, $sFpwUrl, $sReqPath, $mReqHeader, $sReqBody, &$iStatusCode, &$mFpwHeader, &$sResBody) {
        if (!$this->bIsThinkPHP) {
            return;
        }

        $_SERVER['REQUEST_URI'] = $sFpwUrl;

        // 通过ThinkPHP框架处理用户请求
        $pathinfo = ltrim($sReqPath, '/');

        $request = $this->app->request;
        $request->setPathinfo($pathinfo);
        $request->withInput($sReqBody);

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
        $sResBody = $content;

        return true;
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

    public function FileServer($sReqPath, &$mFpwHeader, &$sResBody) {
        // 实现静态文件服务器
        $filepath = $this->getFilePath($this->sWwwrootDir, $this->aDefaDocument, $sReqPath);
        if ($filepath) {
            $ext = pathinfo($filepath, PATHINFO_EXTENSION);
            if (isset($this->mMimeType[$ext])) {
                $mFpwHeader['content-type'] = $this->mMimeType[$ext];
                $sResBody = file_get_contents($filepath);
                return true;
            }
        }
    }

    public function run($fCallback) {
        $this->run2(function ($sFpwUIP, $sFpwMethod, $sFpwUrl, $mReqHeader, $sReqBody, $sReqPath, $mReqQuery, $mReqForm) use ($fCallback) {
            return $fCallback($sFpwUIP, $sFpwMethod, $sFpwUrl, $mReqHeader, $sReqBody, $sReqPath, $mReqQuery, $mReqForm);
        });
    }

    private function run2($fCallback) {
        $this->run1(function ($sFpwUIP, $sFpwMethod, $sFpwUrl, $mReqHeader, $sReqBody) use ($fCallback) {
            $mUrl = parse_url($sFpwUrl);
            $sReqPath = $mUrl['path'];
            $sReqPath = str_replace('..', '', $sReqPath);
            $mReqQuery = array();
            if (isset($mUrl['query'])) {
                parse_str($mUrl['query'], $mReqQuery);
            }
            $mReqForm = array();
            if ($sReqBody) {
                parse_str($sReqBody, $mReqForm);
            }
            return $fCallback($sFpwUIP, $sFpwMethod, $sFpwUrl, $mReqHeader, $sReqBody, $sReqPath, $mReqQuery, $mReqForm);
        });
    }

    private function run1($fCallback) {
        $mResHeader = array();
        $this->setHeaderByFpwInfo($mResHeader);
        $sResBody = '';
        while (true) {
            $req = $this->getRequest($mResHeader, $sResBody);
            if (!$req) continue;
            if (!isset($req[0]['fpw-rid'])) continue;
            $sFpwUIP = $req[0]['fpw-uip'];
            $sFpwMethod = $req[0]['fpw-method'];
            $sFpwUrl = $req[0]['fpw-url'];
            $mReqHeader = isset($req[0]['fpw-header']) ? json_decode($req[0]['fpw-header'], true) : array();
            list($iStatusCode, $mFpwHeader, $sResBody) = $fCallback($sFpwUIP, $sFpwMethod, $sFpwUrl, $mReqHeader, $req[1]);
            $this->setHeaderByFpwInfo($mResHeader);
            $mResHeader['fpw-rid'] = $req[0]['fpw-rid'];
            $mResHeader['fpw-status'] = $iStatusCode;
            $mResHeader['fpw-header'] = json_encode($mFpwHeader);
        }
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

    private function getRequest($mResHeader, $sResBody) {
        return $this->getRequest_curl($mResHeader, $sResBody);
    }

    private function getRequest_file($mResHeader, $sResBody) {
        // 采用 file_get_contents 写的 http请求
        //$mResHeader['connection'] = 'Keep-Alive';
        $mResHeader['user-agent'] = 'php-worker-v1';
        $mResHeader['content-type'] = 'application/octet-stream';
        $mResHeader['content-length'] = strlen($sResBody);
        $opts = array();
        $opts['http'] = array();
        $opts['http']['timeout'] = $this->iTimeout;
        $opts['http']['method'] = 'POST';
        //$opts['http']['protocol_version'] = '1.1';
        $opts['http']['header'] = implode("\r\n", $this->header_mtoa($mResHeader));
        $opts['http']['content'] = $sResBody;
        $cxContext = stream_context_create($opts);
        $sBody = @file_get_contents($this->sServerUrl, false, $cxContext);
        $aHeader = isset($http_response_header) ? $http_response_header : array();
        return $this->getResponse($sBody, $aHeader);
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

    private function getRequest_curl($mResHeader, $sResBody) {
        $mResHeader['Content-Type'] = 'application/octet-stream';
        // 采用 curl 写的 http请求
        curl_setopt($this->ch, CURLOPT_TCP_KEEPALIVE, true);
        curl_setopt($this->ch, CURLOPT_TCP_KEEPIDLE, 120);
        curl_setopt($this->ch, CURLOPT_TCP_KEEPINTVL, 60);
        curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($this->ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($this->ch, CURLOPT_URL, $this->sServerUrl);
        curl_setopt($this->ch, CURLOPT_USERAGENT, 'php-worker-v1');
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->ch, CURLOPT_HEADER, true);
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, $this->header_mtoa($mResHeader));
        curl_setopt($this->ch, CURLOPT_POST, true);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, $sResBody);
        $curl_data = curl_exec($this->ch);
        if ($curl_data === FALSE) {
            $curl_errno = curl_errno($this->ch);
            $curl_error = curl_error($this->ch);
            echo("[Error] {$curl_errno} {$curl_error}");
            return;
        }
        return $this->getHeaderBodyByCurl($curl_data);
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
        return array($this->header_atom($aHeader), $sBody);
    }

}
