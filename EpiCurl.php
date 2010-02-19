<?php
class EpiCurl
{
  private static $inst = array();
  const multi = 'EpiCurlMulti';
  const easy  = 'EpiCurlEasy';
  public static function getInstance($mode = self::easy)
  {
    if(isset(self::$inst[$mode]))
      return self::$inst[$mode];
    
    switch($mode)
    {
      case self::easy:
        self::$inst[$mode] = EpiCurlEasy::getInstance(self::easy);
        return self::$inst[$mode];
      case self::multi:
        self::$inst[$mode] = EpiCurlMulti::getInstance(self::multi);
        return self::$inst[$mode];
    }
  }
}

class EpiCurlEasy extends EpiCurlAbstract
{
  protected function __construct() {}

  public function addCurl($ch)
  {
    $key = $this->getKey($ch);
    $this->responses[$key]['data'] = curl_exec($ch);
    foreach($this->properties as $name => $prop)
    {
      $this->responses[$key][$name] = curl_getinfo($ch, $prop);
    }
    curl_close($ch);
    return new EpiCurlResponse($key, $this);
  }

  public function getResult($key = null)
  {
    if($key !== null)
    {
      if(isset($this->responses[$key]['data']))
        return $this->responses[$key];
      else
        return null;
    }
    return false;
  }
}

class EpiCurlMulti extends EpiCurlAbstract
{
  private $mc;
  private $msgs;
  private $running;
  private $execStatus;
  private $selectStatus;
  private $sleepIncrement = 1.1;
  private $requests = array();

  protected function __construct()
  {
    $this->mc = curl_multi_init();
  }

  public function addCurl($ch)
  {
    $key = $this->getKey($ch);
    $this->requests[$key] = $ch;
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, array($this, 'headerCallback'));

    $code = curl_multi_add_handle($this->mc, $ch);
    
    // (1)
    if($code === CURLM_OK || $code === CURLM_CALL_MULTI_PERFORM)
    {
      do {
          $code = $this->execStatus = curl_multi_exec($this->mc, $this->running);
      } while ($this->execStatus === CURLM_CALL_MULTI_PERFORM);

      return new EpiCurlResponse($key, $this);
    }
    else
    {
      return $code;
    }
  }

  public function getResult($key = null)
  {
    if($key !== null)
    {
      if(isset($this->responses[$key]))
      {
        return $this->responses[$key];
      }

      $innerSleepInt = $outerSleepInt = 1;
      while($this->running && ($this->execStatus == CURLM_OK || $this->execStatus == CURLM_CALL_MULTI_PERFORM))
      {
        usleep($outerSleepInt);
        $outerSleepInt *= $this->sleepIncrement;
        $ms=curl_multi_select($this->mc, 0);
        if($ms > 0)
        {
          do{
            $this->execStatus = curl_multi_exec($this->mc, $this->running);
            usleep($innerSleepInt);
            $innerSleepInt *= $this->sleepIncrement;
          }while($this->execStatus==CURLM_CALL_MULTI_PERFORM);
          $innerSleepInt = 1;
        }
        $this->storeResponses();
        if(isset($this->responses[$key]['data']))
        {
          return $this->responses[$key];
        }
        $runningCurrent = $this->running;
      }
      return null;
    }
    return false;
  }

  private function headerCallback($ch, $header)
  {
    $_header = trim($header);
    $colonPos= strpos($_header, ':');
    if($colonPos > 0)
    {
      $key = substr($_header, 0, $colonPos);
      $val = preg_replace('/^\W+/','',substr($_header, $colonPos));
      $this->responses[$this->getKey($ch)]['headers'][$key] = $val;
    }
    return strlen($header);
  }

  private function storeResponses()
  {
    while($done = curl_multi_info_read($this->mc))
    {
      $key = $this->getKey($done['handle']);
      $this->responses[$key]['data'] = curl_multi_getcontent($done['handle']);
      foreach($this->properties as $name => $const)
      {
        $this->responses[$key][$name] = curl_getinfo($done['handle'], $const);
      }
      curl_multi_remove_handle($this->mc, $done['handle']);
      curl_close($done['handle']);
    }
  }
}

abstract class EpiCurlAbstract
{
  protected static $inst = null;
  protected $responses = array();
  protected $properties = array(
    'code'  => CURLINFO_HTTP_CODE,
    'time'  => CURLINFO_TOTAL_TIME,
    'length'=> CURLINFO_CONTENT_LENGTH_DOWNLOAD,
    'type'  => CURLINFO_CONTENT_TYPE,
    'url'   => CURLINFO_EFFECTIVE_URL
    );

  protected function getKey($ch)
  {
    return (string)$ch;
  }

  public static function getInstance($class)
  {
    if(self::$inst === null)
      self::$inst = new $class();

    return self::$inst;
  }

  abstract public function addCurl($ch);
  abstract public function getResult($key = null);
}

class EpiCurlResponse
{
  private $key;
  private $epiCurl;

  public function __construct($key, $epiCurl)
  {
    $this->key = $key;
    $this->epiCurl = $epiCurl;
  }

  function __get($name)
  {
    $responses = $this->epiCurl->getResult($this->key);
    return $responses[$name];
  }

  function __isset($name)
  {
    $val = self::__get($name);
    return empty($val);
  }
}

/*
 * Credits:
 *  - (1) Alistair pointed out that curl_multi_add_handle can return CURLM_CALL_MULTI_PERFORM on success.
 */
