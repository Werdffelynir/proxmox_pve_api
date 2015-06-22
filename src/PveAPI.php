<?php

class PVEException extends RuntimeException {}

class PveAPI
{
    private $hostname;
    private $username;
    private $userpass;
    private $realm;
    private $port;

    private $debug;
    private $ticket;
    private $apiUrl;
    private $debugInfo;
    private $apiUrlCurrent;
    private $responseType = 'json';
    private $responseData = null;
    private $nodes = array();


    public function __construct(array $configure)
    {
        $throw = false;
        $this->hostname = !empty($configure['hostname']) ? $configure['hostname'] : $throw = true;
        $this->username = !empty($configure['username']) ? $configure['username'] : $throw = true;
        $this->userpass = !empty($configure['userpass']) ? $configure['userpass'] : $throw = true;
        $this->realm = !empty($configure['realm']) ? $configure['realm'] : 'pve';
        $this->port = !empty($configure['port']) ? $configure['port'] : 8006;

        if($throw)
            throw new PVEException('Require set configure in array hostname,username,userpass,realm');

        $this->apiUrl = "https://$this->hostname:$this->port/api2";
        $this->clearTicket();
        $this->tryLogin();
    }


    /**
     * Получить текущий API Url
     * @return string
     */
    public function apiUrl()
    {
        return $this->apiUrl. '/' . $this->responseType;
    }


    /**
     * Последний результат запроса
     * @return null|mixed
     */
    public function lastResponseData() {
        return $this->responseData;
    }


    /**
     * Установка типа получаемого ответа данных
     * @param string $responseType
     */
    public function setResponseType($responseType = 'json') {
        $supportedFormats = array('json', 'html', 'extjs', 'text', 'png');
        if (in_array($responseType, $supportedFormats)) {
            $this->responseType = $responseType;
        }
    }


    /**
     * Вернет тип возврата данных
     * @return string
     */
    public function getResponseType() {
        return $this->responseType;
    }


    /**
     * Включение дебагера
     * @param bool $debug
     */
    public function debug($debug=false)
    {
        $this->debug = $debug;
    }


    /**
     * Отображет результат дебагера
     * @param bool $print
     * @return string
     */
    public function debugPrint($print=true)
    {
        if(!$this->debug) return null;

        $html  = "\n\n------------  D E B U G   L O G  -------------\n";
        $html .= "Request url: {$this->apiUrlCurrent}\n";
        $html .= $this->debugInfo."\n\n";

        if($print)
            echo '<pre>'.$html.'</pre>';
        else
            return $html;
    }

    private function debugAddInfo()
    {
        if($this->debug){
            $lines = func_get_args();
            if(!empty($lines)){
                foreach($lines as $line){
                    $this->debugInfo .= "----------------------------------------------\n";
                    $this->debugInfo .= "$line\n\n";
                }
            }
        }
    }


    /**
     * Очистка авторизации
     */
    private function clearTicket()
    {
        $this->ticket = array(
            'time'=>null,
            'ticket'=>null,
            'username'=>null,
            'CSRFPreventionToken'=>null,
        );
    }


    /**
     * Авторизация
     * @return bool
     */
    private function tryLogin()
    {
        $ch = curl_init();
        $url = $this->apiUrlCurrent = $this->apiUrl().'/access/ticket';
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "username=$this->username@$this->realm&password=$this->userpass" );
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $resultCurl = curl_exec($ch);
        $errorCurl = curl_error($ch);
        curl_close($ch);

        $returned = false;
        if(!empty($errorCurl)){
            $this->debugAddInfo("Error curl request: ".$errorCurl);
        } else if(!empty($resultCurl)) {

            $result = json_decode($resultCurl, true);
            if(!empty($result['data']['CSRFPreventionToken'])){
                $this->ticket['time'] = time();
                $this->ticket['ticket'] = $result['data']['ticket'];
                $this->ticket['username'] = $result['data']['username'];
                $this->ticket['CSRFPreventionToken'] = $result['data']['CSRFPreventionToken'];
                $returned = true;
                $this->responseData = true;
            }else
                throw new PVEException('Request params empty - ticket / CSRFPreventionToken');
        }else
            throw new PVEException('Request CURL empty');

        return $returned;
    }


    /**
     * Проверка тикета после авторизации
     * @return bool
     */
    private function checkTicket() {
        if(empty($this->ticket['ticket']) || $this->ticket['time'] > (time()+7200)) {
            $this->debugAddInfo("Not logged into Proxmox host. No Login access ticket found or ticket expired");
            return false;
        } else
            return true;
    }

    /**
     * Общий обработчик запросов
     * @param $actionPath
     * @param string $method
     * @param array $params
     * @return bool
     */
    private function action($actionPath, $method='GET', $params=array())
    {
        if (!$this->checkTicket()) return false;

        $url = $this->apiUrlCurrent = $this->apiUrl() .'/'. trim($actionPath,'/');

        $putPostHeaders = array();
        $putPostHeaders['PVEAuthCookie'] = $this->ticket['ticket'];

        if ($method != 'GET')
            $putPostHeaders[] = 'CSRFPreventionToken: '.$this->ticket['CSRFPreventionToken'];

        $putPostFields = http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);

        switch ($method) {
            case 'GET':
                // do nothing
                curl_setopt($ch, CURLOPT_POST, false);
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $putPostFields);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $putPostHeaders);
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $putPostFields);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $putPostHeaders);
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
                curl_setopt($ch, CURLOPT_HTTPHEADER, $putPostHeaders);
                break;
            default:
                throw new PVEException("HTTP Request method {$method} not allowed.");
        }

        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_COOKIE, "PVEAuthCookie=" . $this->ticket['ticket']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $resultCurl = curl_exec($ch);
        $errorCurl = curl_error($ch);
        $errnoCurl = curl_errno($ch);

        curl_close($ch);
        unset($ch);

        if (!empty($errorCurl)) {
            $this->debugAddInfo("Error Request. CURL handle: $errorCurl \n CURL ERRNO: $errnoCurl");
            return false;
        }

        if (empty($resultCurl)) {
            $this->debugAddInfo("Error empty Request {$method}  \nWith params " . join(', ', $params)."\nCURL ERRNO: " . $errnoCurl);
            return false;
        } else {

            $splitResultCurl = explode("\r\n\r\n", $resultCurl, 2);
            $headerResult = $splitResultCurl[0];
            $bodyResult = $splitResultCurl[1];
            $resultArray = json_decode($bodyResult, true);
            $resultArrayExport = var_export($resultArray, true);

            $this->debugAddInfo(
                "FULL RESPONSE:\n\n{$resultCurl}",
                "HEADERS:\n\n{$headerResult}",
                "DATA:\n\n{$bodyResult}",
                "RESPONSE ARRAY:\n\n{$resultArrayExport}"
            );

            $splitHeaders = explode("\r\n", $headerResult);
            if (substr($splitHeaders[0], 0, 9) == "HTTP/1.1 ")
            {
                $splitLine = explode(" ", $splitHeaders[0]);
                if ($splitLine[1] == "200")
                {
                    if ($method == "PUT")
                        return true;
                    else
                        return $this->responseData = $resultArray['data'];
                }
                else
                {
                    $this->debugAddInfo("This API Request Failed.\n" ."HTTP Response - {$splitLine[1]}\n" ."HTTP Error - {$splitHeaders[0]}");
                    return false;
                }
            } else {
                $this->debugAddInfo("Error - Invalid HTTP Response.\n" . var_export($splitHeaders, true));
                return false;
            }
        }
    }


    /**
     * Проверка авторизации пользователя на стороне Proxmox
     * @return bool
     */
    public function login() {
        return $this->checkTicket();
    }


    /**
     * Отправит запрос типа GET
     * @param string $actionPath
     * @return mixed
     */
    public function get($actionPath) {
        return $this->action($actionPath, "GET");
    }


    /**
     * Отправит запрос типа PUT
     * @param string $actionPath
     * @param array $parameters
     * @return bool
     */
    public function put($actionPath, $parameters) {
        return $this->action($actionPath, "PUT", $parameters);
    }


    /**
     * Отправит запрос типа POST
     * @param string $actionPath
     * @param array $parameters
     * @return bool
     */
    public function post($actionPath, $parameters) {
        return $this->action($actionPath, "POST", $parameters);
    }


    /**
     * Отправит запрос типа DELETE
     * @param string $actionPath
     * @return bool
     */
    public function delete($actionPath) {
        return $this->action($actionPath, "DELETE");
    }



    # Quick func


    /**
     * Retrieves Proxmox version
     * @return bool|string
     */
    public function getVersion()
    {
        $version = $this->get("/version");
        if ($version)
            return $version['version'];
        return false;
    }

    /**
     * Retrieves the '/access' resource of the Proxmox API resources tree.
     *
     * @param string $path domains,groups,roles,users,acl,ticket
     * @return mixed
     */
    public function getAccess($path=null)
    {
        if (!$path)
            return $this->get('/access');

        return $this->get('/access/'.trim($path,'/'));
    }

    /**
     * Retrieves the '/nodes' resource of the Proxmox API resources tree.
     *
     * @param string $node return accessible by all authententicated users.
     * @return mixed
     */
    public function getNodes($node=null)
    {
        if (!$node)
            return $this->get('/nodes');

        return $this->get('/nodes/'.trim($node,'/'));
    }


    /**
     * @return bool|mixed
     */
    public function getListUsers() {
        return $this->getAccess('users');
    }


    /**
     * @return mixed
     */
    public function getListRole() {
        return $this->getAccess('roles');
    }

    /**
     * Get Access Control List (ACLs).
     * @return mixed
     */
    public function getListAcl() {
        return $this->getAccess('acl');
    }

    /**
     * Virtual machine index (per node).
     * @param $node
     * @return mixed
     */
    public function getListVms($node) {
        return $this->getNodes($node.'/qemu');
    }

    /**
     * List node names
     * @return array
     */
    public function getListNodes()
    {
        if(empty($this->nodes)){
            $nodes = $this->getNodes();
            foreach($nodes as $node){
                if($node['type'] == 'node')
                    $this->nodes[] = $node['node'];
            }
        }
        return $this->nodes;
    }


    /**
     * Create new user.
     *
     *<pre>
     *  userid      string
     *  comment     string
     *  email       string
     *  enable      boolean
     *  expire      integer
     *  firstname   string
     *  groups      string
     *  keys        string
     *  lastname    string
     *  password    string
     *</pre>
     * @param array $config
     * @return bool
     */
    public function createUser(array $config)
    {
        if(empty($config['userid']))
            throw new PVEException('require main param "userid"');

        return $this->post('/access/users',$config);
    }

    /**
     * Update user configuration.
     *
     * @param array $config
     * @return bool
     */
    public function updateUser(array $config)
    {
        if(empty($config['userid']))
            throw new PVEException('require main param "userid"');

        $userid = $config['userid'];
        unset($config['userid']);

        return $this->put('/access/users/'.$userid, $config);
    }

    /**
     * Change user password.
     *
     * <pre>
     * array(
     *      'userid'=>'test[@]pve',
     *      'password'=> 'new pass xxx'
     * );
     * </pre>
     * @param array $config
     * @return bool
     */
    public function updateUserPassword(array $config)
    {
        if(empty($config['userid']))
            throw new PVEException('require main param "userid"');

        return $this->put('/access/password', $config);
    }

    /**
     * Delete user.
     *
     * @param $userid
     * @return bool
     */
    public function deleteUser($userid)
    {
        return $this->delete('/access/users/'.$userid);
    }

    /**
     * Add Access Control List permissions.
     *
     * @param array $config
     * @return bool
     */
    public function createAcl(array $config)
    {
        if(empty($config['path']) || empty($config['roles']))
            throw new PVEException('require main param "path" and "roles"');

        return $this->put('/access/acl',$config);
    }

    /**
     * Update Access Control List permissions.
     *
     * @param array $config
     * @return bool
     */
    public function updateAcl(array $config)
    {
        if(empty($config['path']) || empty($config['roles']))
            throw new PVEException('require main param "path" and "roles"');

        return $this->createAcl($config);
    }

    /**
     * Remove permissions from Access Control List.
     *
     * <pre>
     * array(
     *      'path'=>'/vms/200',
     *      'roles'=>'PVEUser'
     *  );
     * </pre>
     * @param array $config
     * @return bool
     */
    public function deleteAcl(array $config)
    {
        if(empty($config['path']) || empty($config['roles']))
            throw new PVEException('require main param "path" and "roles"');

        $config['delete'] = '1';
        return $this->createAcl($config);
    }

}