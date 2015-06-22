<?php


class PveControl
{
    /** @var string Proxmox default path /vms/<vmid> */
    private $path = '/vms/';
    /** @var PveAPI  */
    private $prox;
    /** @var string  */
    private $realm;
    /** @var string  */
    private $hostname;
    /** @var array  */
    private $subjects = array();

    public function __construct($configure)
    {
        $this->prox = new PveAPI($configure);
        $this->realm = $configure['realm'];
        $this->hostname = $configure['hostname'];
    }

    public function prox()
    {
        return $this->prox;
    }

    public function login()
    {
        return $this->prox->login();
    }

    /**
     * Включение дебагера
     * @param bool $debug
     */
    public function debug($debug=false)
    {
        $this->prox->debug($debug);
    }

    /**
     * @param bool $print
     * @return string
     */
    public function debugPrint($print=true)
    {
        if($print)
            $this->prox->debugPrint(true);
        else
            return $this->prox->debugPrint(false);
    }

    private function _searchUserConfig($userid){
        $users = $this->prox->getAccess('users');
        foreach($users as $user) {
            if($user['userid'] == $userid){
                return $user;
            }
        }
        return false;
    }

    private function _searchUserAclVms($userid)
    {
        $acl = $this->prox->getAccess('acl');
        $aclVms = array();
        foreach($acl as $a) {
            if ($a['ugid'] == $userid) {
                $a['vmid'] = str_ireplace($this->path,'',$a['path']);
                $aclVms[] = $a;
            }
        }
        return $aclVms;
    }

    /**
     * Добавляет для работы пользователя
     *
     * @param $userid
     */
    public function setUser($userid)
    {
        $config = $this->_searchUserConfig($userid);
        $aclVms = $this->_searchUserAclVms($userid);

        $user = array();
        $user['enable'] = $config['enable'];
        $user['userid'] = $userid;
        $user['config'] = $config;
        $user['aclvms'] = $aclVms;

        $this->subjects[$userid] = $user;
    }


    /**
     * @param $userid
     * @return bool
     */
    public function getUser($userid)
    {
        $userid = trim($userid);
        if(isset($this->subjects[$userid]))
            return $this->subjects[$userid];
        return false;
    }

    /**
     * Генератор нового пароля.
     * @param int $max
     * @return null|string
     */
    public function generatePassword($max = 10)
    {
        $chars = "qzxswdcvfrtgbnhujmkolp23456789ZXSWEDCVFRTGBNHUJMKLP";
        $size = strlen($chars)-1;
        $password = null;
        while($max--)
            $password .= $chars[rand(0,$size)];
        return $password;
    }

}