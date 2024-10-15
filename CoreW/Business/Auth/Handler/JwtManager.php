<?php


namespace CoreW\Business\Auth\Handler;


use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use DomainException;
use InvalidArgumentException;
use UnexpectedValueException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use support\utils\ArrayToolkit;

class JwtManager
{
    private $algo;
    private $secret;
    private $public_key;
    private $private_key;
    private $passphrase;
    private $ttl;
    private $refresh_ttl;
    private $required_claims;
    private $leeway;
    private $blacklist_enabled;

    public function __construct($options)
    {
        foreach ($options as $key => $option) {
            if (property_exists($this, $key)) {
                $this->$key = $option;
            }
        }
    }

    /**
     * @return mixed
     */
    public function getAlgo()
    {
        return $this->algo;
    }

    /**
     * @return mixed
     */
    public function getSecret()
    {
        return $this->secret;
    }

    /**
     * @return mixed
     */
    public function getPublicKey()
    {
        return $this->public_key;
    }

    /**
     * @return mixed
     */
    public function getPrivateKey()
    {
        return $this->private_key;
    }

    /**
     * @return mixed
     */
    public function getPassphrase()
    {
        return $this->passphrase;
    }

    /**
     * @return mixed
     */
    public function getTtl()
    {
        return $this->ttl;
    }

    /**
     * @return mixed
     */
    public function getRefreshTtl()
    {
        return $this->refresh_ttl;
    }

    /**
     * @return mixed
     */
    public function getRequiredClaims()
    {
        return $this->required_claims;
    }

    /**
     * @return mixed
     */
    public function getLeeway()
    {
        return $this->leeway;
    }

    /**
     * @return mixed
     */
    public function getBlacklistEnabled()
    {
        return $this->blacklist_enabled;
    }


    /**
     *
     * @param array $payload jwt载荷  格式如下非必须
     * [
     * 'iss'= 'jwt_admin', //该JWT的签发者
     * 'iat'= time(), //签发时间
     * 'exp'= time()+7200, //过期时间,过期时间必须要大于签发时间
     * 'nbf'= time()+60, // 定义在什么时间之前，某个时间点后才能访问
     * 'sub'= 'www.admin.com', //面向的用户
     * 'jti'= md5(uniqid('JWT').time()) //该Token唯一标识
     * ]
     * @param null $headers
     * @return array
     */
    public function makeToken($payload, $headers = null)
    {
        $payload['scopes'] = 'access_token';
        $this->beforeMake($payload);
        $accessToken = JWT::encode($payload, $this->getEncodeKey(), $this->algo, null, $headers);
        $refreshPayload = $payload;
        $refreshPayload['scopes'] = 'refresh_token';
        $refreshPayload['exp'] = time() + $this->refresh_ttl * 60;
        $refreshPayload['jti'] = $refreshPayload['iss'] . $refreshPayload['userId'] . md5(uniqid('refresh_token') . time());
        $refreshToken = JWT::encode($payload, $this->getEncodeKey(), $this->algo, null, $headers);

        return [
            'accessToken' => $accessToken,
            'accessPayload' => $payload,
            'refreshToken' => $refreshToken,
            'refreshPayload' => $refreshPayload
        ];
    }

    public function refreshAccessToken($payload, $headers = null)
    {
        $this->beforeMake($payload);
        $accessToken = JWT::encode($payload, $this->getEncodeKey(), $this->algo, null, $headers);

        return $accessToken;
    }

    protected function beforeMake(&$payload)
    {
        !isset($payload['iat']) && $payload['iat'] = time();
        !isset($payload['nbf']) && $payload['nbf'] = time();
        !isset($payload['exp']) && $payload['exp'] = time() + $this->ttl * 60;
//        var_dump($this->ttl * 60, $payload['exp']);
        !isset($payload['jti']) && $payload['jti'] = $payload['iss'] . $payload['userId'] . md5(uniqid('access_token') . time());
        if (!ArrayToolkit::requireds($payload, $this->required_claims)) {
            throw new BeforeValidException("payload must be have keys:" . implode(',', $this->required_claims));
        }
    }


    public function decode(string $jwt)
    {
        //leeway in seconds
        if (is_numeric($this->leeway)) {
            JWT::$leeway = $this->leeway;
        }

        return JWT::decode($jwt, new Key($this->getDecodeKey(), $this->algo));
    }

    /**
     * @param $jwt
     * @return int|\stdClass 0=过期；-1=其它异常
     */
    public function verifyToken($jwt)
    {
        try {
            $decoded = $this->decode($jwt);

            return $decoded;
        } catch (InvalidArgumentException $e) {
            // provided key/key-array is empty or malformed.
            return -1;
        } catch (DomainException $e) {
            // provided algorithm is unsupported OR
            // provided key is invalid OR
            // unknown error thrown in openSSL or libsodium OR
            // libsodium is required but not available.
            return -1;
        } catch (SignatureInvalidException $e) {
            // provided JWT signature verification failed.
            return -1;
        } catch (BeforeValidException $e) {
            // provided JWT is trying to be used before "nbf" claim OR
            // provided JWT is trying to be used before "iat" claim.
//            echo 'BeforeValidException:', $e->getMessage(), PHP_EOL;
            return -1;
        } catch (ExpiredException $e) {
            // provided JWT is trying to be used after "exp" claim.
            return 0;
        } catch (UnexpectedValueException $e) {
            return -1;
            // provided JWT is malformed OR
            // provided JWT is missing an algorithm / using an unsupported algorithm OR
            // provided JWT algorithm does not match provided key OR
            // provided key ID in key/key-array is empty or invalid.
        }
    }


    protected function getEncodeKey()
    {
        if ($this->algo === 'HS256') {
            return $this->secret;
        }

        if ($this->algo === 'RS256') {
            return $this->parseRS256PrivateKey();
        }

        return null;
    }

    protected function getDecodeKey()
    {
        if ($this->algo === 'HS256') {
            return $this->secret;
        }

        if ($this->algo === 'RS256') {
            return $this->parseRS256PublicKey();
        }

        return null;
    }

    /**
     * @return false|resource|string|null
     */
    protected function parseRS256PrivateKey()
    {
        if (!is_file($this->private_key)) {
            return $this->private_key;
        }

        if (empty($this->passphrase)) {
            return file_get_contents($this->private_key);
        }

        return openssl_pkey_get_private(
            file_get_contents($this->private_key),
            $this->passphrase
        );
    }

    /**
     * @return false|string|null
     */
    protected function parseRS256PublicKey()
    {
        if (!is_file($this->public_key)) {
            return $this->public_key;
        }

        if (empty($this->passphrase)) {
            return file_get_contents($this->public_key);
        }

        $privateKey = $this->parseRS256PrivateKey();

        $result = openssl_pkey_get_details($privateKey);

        return $result && isset($result['key']) ? $result['key'] : null;
    }
}