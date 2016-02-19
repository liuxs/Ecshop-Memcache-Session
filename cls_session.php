<?php
/*****
原贴地址：http://www.oschina.net/code/snippet_83895_6963
亲试可用。

****/
if (!defined('IN_ECS'))
{
    die('Hacking attempt');
}
 
/*------------------------------------------------------ */
//-- 该类用于将SESSION直接写入Memcache
/*------------------------------------------------------ */
class cls_session
{
    var $db             = NULL;
 
    var $max_life_time  = 1800; // SESSION 过期时间
 
    var $session_name   = '';
    var $session_id     = '';
 
    var $session_expiry = '';
    var $session_md5    = '';
 
    var $session_cookie_path   = '/';
    var $session_cookie_domain = '';
    var $session_cookie_secure = false;
 
    var $_ip   = '';
    var $_time = 0;
 
    function __construct(&$db, $session_table, $session_data_table, $session_name = 'ECS_ID', $session_id = '')
    {
        $m = new Memcache;
        $m->addServer('127.0.0.1', 11211);
        $this->cls_session($m, $session_name, $session_id);
    }
 
    function cls_session(&$db, $session_name = 'ECS_ID', $session_id = '')
    {
        $GLOBALS['_SESSION'] = array();
 
        if (!empty($GLOBALS['cookie_path']))
        {
            $this->session_cookie_path = $GLOBALS['cookie_path'];
        }
        else
        {
            $this->session_cookie_path = '/';
        }
 
        if (!empty($GLOBALS['cookie_domain']))
        {
            $this->session_cookie_domain = $GLOBALS['cookie_domain'];
        }
        else
        {
            $this->session_cookie_domain = '';
        }
 
        if (!empty($GLOBALS['cookie_secure']))
        {
            $this->session_cookie_secure = $GLOBALS['cookie_secure'];
        }
        else
        {
            $this->session_cookie_secure = false;
        }
      
        $this->session_name       = $session_name;
 
        $this->db  = &$db;
        $this->_ip = real_ip();
 
        if ($session_id == '' && !empty($_COOKIE[$this->session_name]))
        {
            $this->session_id = $_COOKIE[$this->session_name];
        }
        else
        {
            $this->session_id = $session_id;
        }
 
        if ($this->session_id)
        {
            $tmp_session_id = substr($this->session_id, 0, 32);
            if ($this->gen_session_key($tmp_session_id) == substr($this->session_id, 32))
            {
                $this->session_id = $tmp_session_id;
            }
            else
            {
                $this->session_id = '';
            }
        }
 
        $this->_time = time();
 
        if ($this->session_id)
        {
            $this->load_session();
        }
        else
        {
            $this->gen_session_id();
            setcookie($this->session_name, $this->session_id . $this->gen_session_key($this->session_id), 0, $this->session_cookie_path, $this->session_cookie_domain, $this->session_cookie_secure);
        }
        register_shutdown_function(array(&$this, 'close_session'));
    }
 
    function gen_session_id()
    {
        $this->session_id = md5(uniqid(mt_rand(), true));
 
        return $this->insert_session();
    }
 
    function gen_session_key($session_id)
    {
        static $ip = '';
 
        if ($ip == '')
        {
            $ip = substr($this->_ip, 0, strrpos($this->_ip, '.'));
        }
 
        return sprintf('%08x', crc32(!empty($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] . ROOT_PATH . $ip . $session_id : ROOT_PATH . $ip . $session_id));
    }
 
    function insert_session()
    {
        return $this->db->set($this->session_id, array('expiry'=>$this->_time, 'ip'=>$this->_ip, 'data'=>'a:0:{}'), false, $this->max_life_time);
    }
 
    function load_session()
    {
        $session = $this->db->get($this->session_id);
        if (empty($session))
        {
            $this->insert_session();
            $this->session_expiry = 0;
            $this->session_md5 = '40cd750bba9870f18aada2478b24840a';
            $GLOBALS['_SESSION'] = array();
        }
        else
        {
            if (!empty($session['data']) && $this->_time - $session['expiry'] <= $this->max_life_time)
            {
                $this->session_expiry = $session['expiry'];
                $this->session_md5 = md5($session['data']);
                $GLOBALS['_SESSION']  = unserialize(stripslashes($session['data']));
            }
            else
            {
                $this->session_expiry = 0;
                $this->session_md5 = '40cd750bba9870f18aada2478b24840a';
                $GLOBALS['_SESSION']  = array();
            }
        }
    }
 
    function update_session()
    {
        $adminid = !empty($GLOBALS['_SESSION']['admin_id']) ? intval($GLOBALS['_SESSION']['admin_id']) : 0;
        $userid  = !empty($GLOBALS['_SESSION']['user_id'])  ? intval($GLOBALS['_SESSION']['user_id'])  : 0;
 
        $data = serialize($GLOBALS['_SESSION']);
        $this->_time = time();
 
        if ($this->session_md5 == md5($data) && $this->_time < $this->session_expiry + 10)
        {
            return true;
        }
 
        $data = addslashes($data);
 
        return $this->db->replace($this->session_id, array('expiry'=>$this->_time, 'ip'=>$this->_ip, 'userid'=>$userid, 'adminid'=>$adminid, 'data'=>$data), false, $this->max_life_time);
    }
 
    function close_session()
    {
        $this->update_session();
        return true;
    }
 
    function delete_spec_admin_session($adminid)
    {
        if (!empty($GLOBALS['_SESSION']['admin_id']) && $adminid)
        {
            $all_items = $this->db->getExtendedStats('items');
            $items = $all_items['127.0.0.1:11211']['items'];
            foreach ($items as $key => $item) {
                if (isset($item['adminid'])) {
                    if ($item['adminid'] == $adminid) return $this->db->delete($key);
                }
            }
        }
        else
        {
            return false;
        }
    }
 
    function destroy_session()
    {    
        $GLOBALS['_SESSION'] = array();
 
        setcookie($this->session_name, $this->session_id, 1, $this->session_cookie_path, $this->session_cookie_domain, $this->session_cookie_secure);
 
        /* ECSHOP 自定义执行部分 */
        if (!empty($GLOBALS['ecs']))
        {
            $GLOBALS['db']->query('DELETE FROM ' . $GLOBALS['ecs']->table('cart') . " WHERE session_id = '$this->session_id'");
        }
        /* ECSHOP 自定义执行部分 */
 
        return $this->db->delete($this->session_id);
    }
 
    function get_session_id()
    {
        return $this->session_id;
    }
 
    function get_users_count()
    {
        $all_items = $this->db->getExtendedStats();
        return $count = $all_items['127.0.0.1:11211']['curr_items'];//由于有其他key的缓存，因此这只是个接近数值
    }
 
}
 
?>
