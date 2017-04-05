<?php
    //die();
    error_reporting(E_ALL);
    ini_set("display_errors", 1);
    ini_set("max_execution_time", 3600);

    class Logger
    {
        //статические переменные
        public static $PATH;
        protected static $loggers = array();

        protected $name;
        protected $file;
        protected $fp;

        public function __construct($name, $file = null)
        {
            $this->name = $name;
            $this->file = $file;

            $this->open();
        }

        public function open()
        {
            if (self::$PATH == null) {
                return;
            }

            $this->fp = fopen($this->file == null ? self::$PATH . '/' . $this->name . '.log' : self::$PATH . '/' . $this->file, 'a+');
        }

        public static function getLogger($name = 'root', $file = null)
        {
            if (!isset(self::$loggers[$name])) {
                self::$loggers[$name] = new Logger($name, $file);
            }

            return self::$loggers[$name];
        }

        public function log($message)
        {
            if (!is_string($message)) {
                $this->logPrint($message);

                return;
            }

            $log = '';

            $log .= '[' . date('D M d Y H:i:s', time()) . '] ';
            if (func_num_args() > 1) {
                $params = func_get_args();

                $message = call_user_func_array('sprintf', $params);
            }

            $log .= $message;
            $log .= "\n";

            //$this->_write($log);
        }

        public function logPrint($obj)
        {
            ob_start();

            print_r($obj);

            $ob = ob_get_clean();
            $this->log($ob);
        }

        protected function _write($string)
        {
            fwrite($this->fp, $string);

            //echo $string;
        }

        public function __destruct()
        {
            fclose($this->fp);
        }
    }

    //class begin
    class amocrmPushCallsFromAsterisk
    {
        ### Mysql
        public $asterisk_db_type = 'mysql';
        public $asterisk_db_host = 'localhost';
        public $asterisk_db_port = '3306';
        public $asterisk_db_user = 'root';
        public $asterisk_db_pass = '';
        public $asterisk_db_name = 'asteriskcdrdb';
        public $asterisk_db_table_name = 'cdr';
        public $asterisk_db_options = array();

        public $amocrmApiKey         = ''; // ключ API от Amocrm
        public $amocrmUsername       = ''; // логин в AmoCrm
        public $amocrmDomain         = ''; // домен в AmoCrm
        public $leads_statusesIdSort = 10;
        public $cntSkip              = 5; //номер у которого длина <= - не обрабатывается
        public $cntSec               = 15; //если длительность разговора меньше этого параметра, то такой разговор не "попадает" в amocrm

	      //regexp по которому выбираются звонки из Asterisk
	      public $regSip               = "'SIP/15|SIP/16|SIP/24|SIP/25|SIP/32|SIP/33|SIP/35|SIP/42|SIP/43'";

        public $amocrmCustomFields = array(
            'ContactFieldPhone'     => 31750, // ID поля номера телефона - берется amocrm
            'ContactFieldEmail'     => 31752, // ID поля емейла - берется amocrm
            'ResponsibleUserId'     => null, // ID ответственного менеджера
            'LeadStatusId'          => null, // ID первого статуса сделки
            'LeadFieldCustom'       => null, // ID кастомного поля сделки
            'LeadFieldCustomValue1' => null, // ID первого значения кастомного поля сделки
            'LeadFieldCustomValue2' => null // ID второго значения кастомного поля сделки
        );

	      //эти "номера" и "логины" задаются в amocrm
        public $amocrmUsersIntNum = array(
            //внутренний номер => login
            15 => '15@telfer-m.ru',
            23 => '23@telfer-m.ru',
            24 => '23@telfer-m.ru',
            25 => '25@telfer-m.ru',
            32 => '32@telfer-m.ru',
            33 => '33@telfer-m.ru',
            35 => '35@telfer-m.ru',
            42 => '42@telfer-m.ru',
            43 => '43@telfer-m.ru',
        );

        public $account = array();

        protected $amocrmAdminID;
        protected $db;

        /**
         * Конструктор класса
         * @throws Exception
         */
        function __construct()
        {
            Logger::$PATH = dirname(__FILE__);

            if (!$this->auth()) {
                throw new Exception('Auth error');
            }

            $this->accountGet();

            try {
                $this->db = new PDO($this->asterisk_db_type . ":host=" . $this->asterisk_db_host . ";port=" . $this->asterisk_db_port . ";dbname=" . $this->asterisk_db_name, $this->asterisk_db_user, $this->asterisk_db_pass, $this->asterisk_db_options);
            } catch (PDOException $e) {
                Logger::getLogger('amocrmPushCallsFromAsterisk')->log("PDO::errorInfo():\n".$e->getMessage());
            }

            $this->dbExec("DELETE FROM ".$this->asterisk_db_table_name." WHERE `channel` LIKE '%SIP/4956%' OR `dstchannel` LIKE '%SIP/4956%' ");
        }

        protected function dbExec($query)
        {
            try {
                $sth = $this->db->prepare($query);
                $sth->execute();
            } catch (PDOException $e) {
                print $e->getMessage();
            }
            if (!$sth) {
                Logger::getLogger('amocrmPushCallsFromAsterisk')->log("PDO::errorInfo():\n".$e->getMessage());
            }

            return $sth;
        }

        /**
         * auth
         */
        protected function auth()
        {
            #Массив с параметрами, которые нужно передать методом POST к API системы
            $user = array(
                'USER_LOGIN' => $this->amocrmUsername, #Ваш логин (электронная почта)
                'USER_HASH'  => $this->amocrmApiKey #Хэш для доступа к API (смотрите в профиле пользователя)
            );
            #Формируем ссылку для запроса
            $link = 'https://' . $this->amocrmDomain . '.amocrm.ru/private/api/auth.php?type=json';
            Logger::getLogger('amocrmPushCallsFromAsterisk')->log("auth: ".$link);
            list($code, $out) = $this->sendCurl($link, $user);

            /**
             * Данные получаем в формате JSON, поэтому, для получения читаемых данных,
             * нам придётся перевести ответ в формат, понятный PHP
             */
            $Response = json_decode($out, true);
            $Response = $Response['response'];
            //echo '<pre>'.print_r($Response,true).'</pre>'."\n\n";
            if (isset($Response['auth'])) #Флаг авторизации доступен в свойстве "auth"
            {
                return true;
            } else {
                return false;
            }
        }

        public function accountGet()
        {
            $link = 'https://' . $this->amocrmDomain . '.amocrm.ru/private/api/v2/json/accounts/current';
            list($code, $out) = $this->sendCurl($link, null, false);
            $Response = json_decode($out, true);

            $this->account = $Response['response']['account'];

            $res=$this->search_array($this->account['users'],'is_admin','Y');//ищем "админа" в массиве юзеров
            if(!isset($res[0]['id']) or empty($res[0]['id']))//не нашли
                throw new Exception('Admin not found');

            $this->amocrmAdminID = $res[0]['id']; // идентификатор юзера, который "админ"

            //get leads status
            foreach ($this->account['leads_statuses'] as $leads)
            {
                if ($leads['sort'] == $this->leads_statusesIdSort)//получаем саму первую сделку
                {
                    $this->amocrmCustomFields['LeadStatusId'] = $leads['id'];
                    break;
                }
            }

            if (!isset($this->amocrmCustomFields['LeadStatusId']) or empty($this->amocrmCustomFields['LeadStatusId'])) {
                throw new Exception('Leads not found');
            }

        }

        protected function getLogin($dbRow)
        {
            $re= "/(SIP|Local)\/([0-9]+)(-|@)(.*)/i";
            if(preg_match($re, $dbRow['dstchannel'], $matches))//входящий звонок
            {
                //ищем в массиве $this->amocrmUsersIntNum соответствие: "внутренний номер"-"логин"
                if(isset($this->amocrmUsersIntNum[$matches[2]]) and !empty($this->amocrmUsersIntNum[$matches[2]]))
                    $login = $this->amocrmUsersIntNum[$matches[2]];
            }

            if(!isset($login) or empty($login))//если логин не нашли, возможно это исходящий звонок
            {
                if (preg_match($re, $dbRow['channel'], $matches))//исходящий звонок
                {
                    //ищем в массиве $this->amocrmUsersIntNum соответствие: "внутренний номер"-"логин"
                    if(isset($this->amocrmUsersIntNum[$matches[2]]) and !empty($this->amocrmUsersIntNum[$matches[2]]))
                        $login = $this->amocrmUsersIntNum[$matches[2]];
                }
            }

            Logger::getLogger('amocrmPushCallsFromAsterisk')->log('getLogin: '.$login);

            return $login;
        }

        /**
         * Добавление нового контакта
         *
         * @param        $dbRow
         * @param        $phone
         * @param string $text
         *
         * @return mixed
         */
        protected function addNewContact($dbRow, $phone, $text='Звонок от ')
        {
            // ставим "ответственного" = "админ" на случай, если не найдем "ответственного"
            $this->amocrmCustomFields['ResponsibleUserId'] = $this->amocrmAdminID;

            $login=$this->getLogin($dbRow);

            $contactNameWithPhone = 'Автоконтакт ' . $phone;

            $data='[addNewContact] "'.$contactNameWithPhone.'" ';
            if(isset($login) and !empty($login))//если "логин" найден
            {
                $amocrmUser = $this->search_array($this->account['users'],'login',$login);//ищем в массиве юзеров от amocrm ИД записи соответствующие "логину"
                if(isset($amocrmUser[0]['id']) and !empty($amocrmUser[0]['id']))//если найдено
                    $this->amocrmCustomFields['ResponsibleUserId'] = (int)$amocrmUser[0]['id'];
                $data.=' => '.$login.' ';
            }
            $data.=' => '.$this->amocrmCustomFields['ResponsibleUserId'];

            Logger::getLogger('amocrmPushCallsFromAsterisk')->log($data);

            $leadsId = $this->leadsAdd($text.$phone,$dbRow['calldate_timestamp']);//сделка
            $contactsId = $this->contactsAdd($contactNameWithPhone, $phone, $leadsId, $dbRow['calldate_timestamp']);//контакт

            return $contactsId;
        }

        /**
         * Связь "звонка" с "контактом"
         *
         * @param     $dbRow
         * @param     $contactsId
         * @param     $phone
         * @param int $element_type
         * @param int $note_type
         */
        protected function callLinking($dbRow, $contactsId, $phone, $element_type = 1, $note_type = 10)
        {
            // ставим "ответственного" = "админ" на случай, если не найдем "ответственного"
            $this->amocrmCustomFields['ResponsibleUserId'] = $this->amocrmAdminID;

            //$note_type = 10;//входящий звонок

            $login = $this->getLogin($dbRow);

            $data="[callLinking] ";
            if(isset($login) and !empty($login))
            {
                $data.='Login: '.$login;

                $amocrmUser = $this->search_array($this->account['users'],'login',$login);//ищем в массиве юзеров от amocrm ИД записи соответствующие "логину"
                if(isset($amocrmUser[0]['id']) and !empty($amocrmUser[0]['id']))//если найдено
                    $this->amocrmCustomFields['ResponsibleUserId'] = (int)$amocrmUser[0]['id'];
            }

            $data.=' => Contact ID: '.$contactsId;
            Logger::getLogger('amocrmPushCallsFromAsterisk')->log($data);

            $params = array(
                'contactsId'  => $contactsId,
                'uniqueid'    => $dbRow['uniqueid'],
                'phone'       => $phone,
                'duration'    => $dbRow['duration'],
                'date_create' => $dbRow['calldate_timestamp']
            );//добавляем заметку "исходящий звонок" от "этого контакта"
            $this->notesAdd($params, $element_type, $note_type);

            //заносим в базу Астериска, ID контакта, который занесен в AmoCrm
            $query = "UPDATE " . $this->asterisk_db_table_name . " set AmoCrm=" . $contactsId . " WHERE uniqueid='" . $dbRow['uniqueid'] . "'";
            $this->dbExec($query);
        }

        /**
         * Добавление сделки
         *
         * $leadsName - название сделки
         */
        protected function leadsAdd($leadsName, $data_create=null)
        {
            $data_create= (!isset($data_create) or empty($data_create))?time():$data_create;

            Logger::getLogger('amocrmPushCallsFromAsterisk')->log("[leadsAdd] ".$leadsName);

            $link = 'https://' . $this->amocrmDomain . '.amocrm.ru/private/api/v2/json/leads/set';
            $data['request']['leads']['add'] = array(
                array(
                    'name'                => $leadsName,
                    'date_create'         => $data_create, //optional
                    'status_id'           => $this->amocrmCustomFields['LeadStatusId'],
                    'responsible_user_id' => $this->amocrmCustomFields['ResponsibleUserId'],
                ),
            );
            list($code, $out) = $this->sendCurl($link, $data);
            $Response = json_decode($out, true);

            return $Response['response']['leads']['add'][0]['id'];
        }

        /**
         * Добавление контакта
         *
         * $contactName - имя контакта
         * $contactPhone - телефон контакта
         * $leadsId - идентификатор связанной сделки
         */
        protected function contactsAdd($contactName, $contactPhone, $leadsId = null, $data_create=null)
        {
            $data_create= (!isset($data_create) or empty($data_create))?time():$data_create;

            Logger::getLogger('amocrmPushCallsFromAsterisk')->log("[contactsAdd] ".$contactName.' '.$contactPhone);

            $link = 'https://' . $this->amocrmDomain . '.amocrm.ru/private/api/v2/json/contacts/set';
            $data['request']['contacts']['add'] = array(
                array(
                    'name'                => $contactName, #Имя контакта
                    'date_create'         => $data_create, //optional
                    'responsible_user_id' => $this->amocrmCustomFields['ResponsibleUserId'],
                    'linked_leads_id'     => array($leadsId),
                    'custom_fields'       => array(
                        array(
                            #Телефоны
                            'id'     => $this->amocrmCustomFields['ContactFieldPhone'],
                            #Уникальный индентификатор заполняемого дополнительного поля
                            'values' => array(
                                array(
                                    'value' => $contactPhone,
                                    'enum'  => 'OTHER' #Мобильный
                                ),
                            ),
                        ),
                    ),
                ),
            );

            list($code, $out) = $this->sendCurl($link, $data);
            $Response = json_decode($out, true);

            return $Response['response']['contacts']['add'][0]['id'];
        }

        /**
         * Добавление события
         *
         * $phone - номер телефона
         * $contactsId - идентификатор контакта
         * $element_type - идентификатор типа события: 1 = контакт; 2 = сделка; 3 = компания
         * $note_type - идентификатор типа звонка: 10 = входящий ; 11 = исходящий
         */
        protected function notesAdd($params=array(), $element_type = 1, $note_type = 10, $text='New note')
        {
            Logger::getLogger('amocrmPushCallsFromAsterisk')->log("[notesAdd] ".$element_type.' '.$note_type);

            $link = 'https://' . $this->amocrmDomain . '.amocrm.ru/private/api/v2/json/notes/set';

            if(in_array($note_type,array(10,11)))
            {
                $text = json_encode( //старый формат данных, чтобы не переписывать бекенд и фронтенд amoCRM
                    array(
                        'UNIQ' => $params['uniqueid'], //строка с уникальным ID
                        'PHONE' => $params['phone'], //номер телефона
                        'DURATION' => $params['duration'], //длительность в секундах
                        'SRC'=>'sip', //идентификатор виджета телефонии
                        'LINK'=>'https://' . $this->amocrmDomain . '.amocrm.ru/private/acceptors/asterisk_new/?GETFILE='.$params['uniqueid']
                    )
                );
            }

            $data['request']['notes']['add'] = array(
                array(
                    'element_id'      => $params['contactsId'],
                    'date_create'     => $params['date_create'],
                    'element_type'    => $element_type,
                    'note_type'       => $note_type,
                    'created_user_id' => $this->amocrmCustomFields['ResponsibleUserId'],
                    'text' => $text
                )
            );
            list($code, $out) = $this->sendCurl($link, $data);
            $Response=json_decode($out,true);
            $Response=$Response['response']['notes']['add'];

            return $Response;
        }

        protected function unsortedAdd($phone,$name='Звонок от ')
        {
            $link = 'https://' . $this->amocrmDomain . '.amocrm.ru/api/unsorted/add/?api_key=' . $this->amocrmApiKey . '&login=' . $this->amocrmUsername;

            Logger::getLogger('amocrmPushCallsFromAsterisk')->log("[unsortedAdd] ".$link);

            $data=array();
            $data['request']['unsorted'] = array(
                'category' => 'sip',
                'add' => array(
                    array(
                        'source'      => 686,
                        'source_uid'  => uniqid(),
                        'date_create' => time(),
                        'data'        => array(
                            'leads'    => array(
                                array(
                                    'name' => $name.$phone,
                                    'date_create'         => time(), //optional
                                    'responsible_user_id' => $this->amocrmAdminID,
                                ),
                            ),
                            'contacts' => array(
                                array(
                                    'name'          => 'Автоконтакт '.$phone,
                                    'responsible_user_id' => $this->amocrmAdminID,
                                    'custom_fields' => array(
                                        array(
                                            'id'     => $this->amocrmCustomFields['ContactFieldPhone'],
                                            'values' => array(
                                                array(
                                                    'enum'  => 72802,
                                                    'value' => $phone,
                                                ),
                                            ),
                                        ),
                                    ),
                                ),
                            ),
                        ),
                        'source_data' => array(
                            'from'     => $phone,
                            'to'       => $this->amocrmAdminID,
                            'date'     => time(),
                            'duration' => 15,
                            'link'     => $this->amocrmDomain,
                            'service'  => $this->amocrmDomain,
                        ),
                    ),
                ),
            );

            list($code, $out) = $this->sendCurl($link, $data);
            $Response = json_decode($out, true);
            return $Response['response']['unsorted']['add']['status'];
        }

        protected function contactList($query)
        {
            Logger::getLogger('amocrmPushCallsFromAsterisk')->log('[contactList] '.$query);

            $link = 'https://' . $this->amocrmDomain . '.amocrm.ru/private/api/v2/json/contacts/list?query='.$query;
            list($code, $out) = $this->sendCurl($link, null, false);
            $Response = json_decode($out, true);
            return $Response['response'];
        }

        protected function companyList($query)
        {
            Logger::getLogger('amocrmPushCallsFromAsterisk')->log('[companyList] '.$query);

            $link = 'https://' . $this->amocrmDomain . '.amocrm.ru/private/api/v2/json/company/list?query='.$query;
            list($code, $out) = $this->sendCurl($link, null, false);
            $Response = json_decode($out, true);
            return $Response['response'];
        }

        protected function sendCurl($link, $data = null, $isPost = true)
        {
            //echo '[sendCurl] '.$link."\n\n";
            $curl = curl_init(); #Сохраняем дескриптор сеанса cURL
            #Устанавливаем необходимые опции для сеанса cURL
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_USERAGENT, 'amoCRM-API-client/1.0');
            curl_setopt($curl, CURLOPT_URL, $link);
            if ($isPost) {
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
            }
            if (isset($data)) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
            }

            //curl_setopt($curl,CURLOPT_HTTPHEADER,array('IF-MODIFIED-SINCE: Mon, 01 Aug 2013 07:07:23'));
            curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_COOKIEFILE, dirname(__FILE__) . '/cookie.txt'); #PHP>5.3.6 dirname(__FILE__) -> __DIR__
            curl_setopt($curl, CURLOPT_COOKIEJAR, dirname(__FILE__) . '/cookie.txt'); #PHP>5.3.6 dirname(__FILE__) -> __DIR__
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);

            $out = curl_exec($curl); #Инициируем запрос к API и сохраняем ответ в переменную
            $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); #Получим HTTP-код ответа сервера
            curl_close($curl); #Завершаем сеанс cURL

            $code = (int)$code;
            $errors = array(
                110 => 'Incorrect login or password',
                301 => 'Moved permanently',
                400 => 'Bad request',
                401 => 'Unauthorized',
                403 => 'Forbidden',
                404 => 'Not found',
                500 => 'Internal server error',
                502 => 'Bad gateway',
                503 => 'Service unavailable',
            );
            try {
                #Если код ответа не равен 200 или 204 - возвращаем сообщение об ошибке
                if ($code != 200 && $code != 204) {
                    throw new Exception(isset($errors[$code]) ? $errors[$code] : 'Undescribed error', $code);
                }
            } catch (Exception $E) {
                die('Ошибка: ' . $E->getMessage() . PHP_EOL . 'Код ошибки: ' . $E->getCode());
            }

            //echo $out."\n";
            //Logger::getLogger('amocrmPushCallsFromAsterisk')->log("sendCurl:out ".$out);
            Logger::getLogger('amocrmPushCallsFromAsterisk')->log("[sendCurl] ".$link."\n");

            return array($code, $out);
        }

        protected function search_array ( $array, $key, $value )
        {
            $results = array();

            if ( is_array($array) )
            {
                if ( @$array[$key] == $value )
                {
                    $results[] = $array;
                } else {
                    foreach ($array as $subarray)
                        $results = array_merge( $results, $this->search_array($subarray, $key, $value) );
                }
            }

            return $results;
        }

        /**
         * Дергаем базу Астериска
         *
         * @param string $mode: src - входящий звонок, dst - исходящий
         * @param null   $phone
         */
        protected function checkCdr($mode='src',$phone=null)
        {
            $phone = (isset($phone) and !empty($phone)) ? " and ".$mode." LIKE '%".$phone."%'" : " ";

            $regexp = " and channel REGEXP ".$this->regSip;
            $note_type = 11; //исходящий звонок
            if($mode=='src') {
                $note_type = 10; //входящий звонок
                $regexp = " and dstchannel REGEXP ".$this->regSip;
            }

            $query = "SELECT calldate, UNIX_TIMESTAMP(calldate) as calldate_timestamp, uniqueid, src, dst, channel, dstchannel, duration, did
                      FROM " . $this->asterisk_db_table_name . "
                      WHERE
                        calldate >= CURDATE() and
                        disposition='ANSWERED' and
                        CHAR_LENGTH(".$mode.")>" . $this->cntSkip . " and
                        AmoCrm is NULL and
                        duration>".$this->cntSec."
                        ".$phone."
                        ".$regexp."
                      ORDER BY calldate DESC";
            //echo $query."\n";
            //Logger::getLogger('amocrmPushCallsFromAsterisk')->log('[checkCdr-'.$mode.'] '.$query);
            $sth=$this->dbExec($query);

            while ($row = $sth->fetch(PDO::FETCH_ASSOC))
            {
                $phone = $row[$mode];
                if($row['did']=='s')// если входящий звонок от МангоТелеком - заменяем "7" в начале номера на "8"
                {
                    $phone = ltrim($row['src'],'7');
                    $phone = '8'.$phone;
                }

                $findByPhone=substr($phone,1);

                $element_type = 1;
                $contactsId = null;

                $companys=$this->companyList($findByPhone);
                $data='[checkCdr-'.$mode.'] Find something by phone: '.$findByPhone." (".$phone.") => ";

                if(isset($companys) and !empty($companys))//если нашли "компанию"
                {
                    $contactsId = (int)$companys['contacts'][0]['id'];//вытаскиваем "идентификатор контакта"
                    $contactsName = $companys['contacts'][0]['name'];
                    $element_type = 3;
                    $data.="found company: ".$contactsName." (".$contactsId.")";
                }
                else
                {
                    $contacts = $this->contactList($findByPhone);
                    if (isset($contacts) and !empty($contacts))//если нашли "контакт"
                    {
                        $contactsId = (int)$contacts['contacts'][0]['id'];
                        $contactsName = $contacts['contacts'][0]['name'];
                        $element_type = 1;
                        $data.="found contact: ".$contactsName." (".$contactsId.")";
                    }
                }

                Logger::getLogger('amocrmPushCallsFromAsterisk')->log($data);

                if (!isset($contactsId) or empty($contactsId)) //если идентификатор НЕ найден
                    $contactsId=$this->addNewContact($row, $phone, 'Звонок к ');

                $this->callLinking($row,$contactsId,$phone,$element_type,$note_type);
                //echo "\n";
            }

        }

        /*
         * Обработка исходящих звонков
         *
         * @param null $phone
         */
        public function callOutgoing($phone=null)
        {
            $this->checkCdr('dst',$phone);
        }

        /**
         * Обработка входящих звонков
         *
         * @param null $phone
         */
        public function callIncoming($phone=null)
        {
            $this->checkCdr('src',$phone);
        }

    }

    //class end

    $a = new amocrmPushCallsFromAsterisk();
    
    if(php_sapi_name() === 'cli')
    {
        $action = (isset($argv[1]) and !empty($argv[1])) ? $argv[1] : null;
        $phone = (isset($argv[2]) and !empty($argv[2])) ? $argv[2] : null;
    }
    else
    {
        $phone = (isset($_GET['phone']) and !empty($_GET['phone'])) ? $_GET['phone'] : null;
        $action = (isset($_GET['action']) and !empty($_GET['action'])) ? $_GET['action'] : null;
    }

    switch($action)
    {
        case 'account'     : { $a->accountGet(); break; }

        case 'callOutgoing': { $a->callOutgoing($phone); break; }

        case 'callIncoming': { $a->callIncoming($phone); break; }

        default            : {
                                $a->callIncoming($phone);
                                $a->callOutgoing($phone);
                                break;
                             }
    }

?>
