<?php

namespace ByJG\Session;

use ByJG\Util\JwtWrapper;
use SessionHandlerInterface;

class JwtSession implements SessionHandlerInterface
{
    /**
     * @var SessionConfig
     */
    protected $sessionConfig;

    /**
     * JwtSession constructor.
     *
     * @param $sessionConfig
     * @throws JwtSessionException
     */
    public function __construct($sessionConfig)
    {
        ini_set("session.use_cookies", 0);

        if (!($sessionConfig instanceof SessionConfig)) {
            throw new JwtSessionException('Required SessionConfig instance');
        }

        $this->sessionConfig = $sessionConfig;

        if ($this->sessionConfig->isReplaceSession()) {
            $this->replaceSessionHandler();
        }
    }

    /**
     * @param bool $startSession
     * @throws JwtSessionException
     */
    protected function replaceSessionHandler()
    {
        if (session_status() != PHP_SESSION_NONE) {
            throw new JwtSessionException('Session already started!');
        }

        session_set_save_handler($this, true);

        if ($this->sessionConfig->isStartSession()) {
            ob_start();
            session_start();
        }
    }

    /**
     * Close the session
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.close.php
     * @return bool <p>
     * The return value (usually TRUE on success, FALSE on failure).
     * Note this value is returned internally to PHP for processing.
     * </p>
     * @since 5.4.0
     */
    public function close()
    {
        return true;
    }

    /**
     * Destroy a session
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.destroy.php
     * @param string $session_id The session ID being destroyed.
     * @return bool <p>
     * The return value (usually TRUE on success, FALSE on failure).
     * Note this value is returned internally to PHP for processing.
     * </p>
     * @since 5.4.0
     */
    public function destroy($session_id)
    {
        return true;
    }

    /**
     * Cleanup old sessions
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.gc.php
     * @param int $maxlifetime <p>
     * Sessions that have not updated for
     * the last maxlifetime seconds will be removed.
     * </p>
     * @return bool <p>
     * The return value (usually TRUE on success, FALSE on failure).
     * Note this value is returned internally to PHP for processing.
     * </p>
     * @since 5.4.0
     */
    public function gc($maxlifetime)
    {
        return true;
    }

    /**
     * Initialize session
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.open.php
     * @param string $save_path The path where to store/retrieve the session.
     * @param string $name The session name.
     * @return bool <p>
     * The return value (usually TRUE on success, FALSE on failure).
     * Note this value is returned internally to PHP for processing.
     * </p>
     * @since 5.4.0
     */
    public function open($save_path, $name)
    {
        return true;
    }

    /**
     * Read session data
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.read.php
     * @param string $session_id The session id to read data for.
     * @return string <p>
     * Returns an encoded string of the read data.
     * If nothing was read, it must return an empty string.
     * Note this value is returned internally to PHP for processing.
     * </p>
     * @since 5.4.0
     */
    public function read($session_id)
    {
        try {
            $data = $this->getBearerTokenData();
            if (sizeof($data) === 0) {
                return '';
            }
            return $this->serializeSessionData($data);
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Write session data
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.write.php
     * @param string $session_id The session id.
     * @param string $session_data <p>
     * The encoded session data. This data is the
     * result of the PHP internally encoding
     * the $_SESSION superglobal to a serialized
     * string and passing it as this parameter.
     * Please note sessions use an alternative serialization method.
     * </p>
     * @return bool <p>
     * The return value (usually TRUE on success, FALSE on failure).
     * Note this value is returned internally to PHP for processing.
     * </p>
     * @throws \ByJG\Util\JwtWrapperException
     * @since 5.4.0
     */
    public function write($session_id, $session_data)
    {
        if (!headers_sent()) {
            $oldTokenData = $this->getBearerTokenData();
            $sessionDataArray = $this->unSerializeSessionData($session_data);

            $mergedData = array_merge($oldTokenData, $sessionDataArray);

            $jsonData = json_encode($mergedData);
            $jwt = new JwtWrapper(
                $this->sessionConfig->getServerName(),
                $this->sessionConfig->getKey()
            );
            $data = $jwt->createJwtData($jsonData, $this->sessionConfig->getTimeoutMinutes() * 60);
            $token = $jwt->generateToken($data);
            header('Access-Control-Expose-Headers: X-Token');
            header('X-Token: '. $token);
        }
        return true;
    }

    public function serializeSessionData($array)
    {
        $result = '';
        foreach ($array as $key => $value) {
            $result .= $key . "|" . serialize($value);
        }
        return $result;
    }

    /**
     * @param $session_data
     * @return array
     * @throws JwtSessionException
     */
    public function unSerializeSessionData($session_data)
    {
        $return_data = [];
        $offset = 0;
        while ($offset < strlen($session_data)) {
            if (!strstr(substr($session_data, $offset), "|")) {
                throw new JwtSessionException("invalid data, remaining: " . substr($session_data, $offset));
            }
            $pos = strpos($session_data, "|", $offset);
            $num = $pos - $offset;
            $varname = substr($session_data, $offset, $num);
            $offset += $num + 1;
            $data = unserialize(substr($session_data, $offset));
            $return_data[$varname] = $data;
            $offset += strlen(serialize($data));
        }

        return $return_data;
    }

    public function getBearerToken() {
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = explode(' ', $_SERVER['HTTP_AUTHORIZATION']);
            $token = end($header);
            if ($token === 'null') {
                return null;
            } else {
                return $token;
            }
        }
    }

    public function getBearerTokenData() {
        $token = $this->getBearerToken();
        if (is_null($token)) {
            return [];
        }
        $jwt = new JwtWrapper(
            $this->sessionConfig->getServerName(),
            $this->sessionConfig->getKey()
        );
        try {
            $data = $jwt->extractData($token);
            if (empty($data->data)) {
                return [];
            }

            $decoded_data = json_decode($data->data, true);
            return $decoded_data;
        } catch (\Exception $e) {
            return [];
        }
    }

	public function mergeRecursive(array &$array1, array &$array2){
		$merged = $array1;

		foreach ($array2 as $key => &$value){
			if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])){
				$merged [$key] = $this->mergeRecursive( $merged [$key], $value );
			}
			else {
				$merged[$key] = $value;
			}
		}
		return $merged;
	}

}
