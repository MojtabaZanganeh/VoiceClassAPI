<?php

namespace Classes\Base;

use Predis\Client;
use Exception;

class Redis
{
    private $redis;
    private $prefix = 'voice_class:';
    private $default_ttl = 300;

    public function __construct()
    {
        $this->connect();
    }

    private function connect()
    {
        $redis_url = $_ENV['UPSTASH_REDIS_URL'] ?? '';
        $redis_token = $_ENV['UPSTASH_REDIS_TOKEN'] ?? '';

        if (!$redis_url || !$redis_token) {
            error_log("Redis configuration missing");
            throw new Exception("Redis configuration missing");
        }

        $parsed = parse_url($redis_url);
        if (!$parsed || !isset($parsed['host']) || !isset($parsed['port'])) {
            throw new Exception("Invalid Redis URL format");
        }

        $this->redis = new Client([
            'scheme' => 'tls',
            'host' => $parsed['host'],
            'port' => $parsed['port'],
            'password' => $redis_token,
            'database' => 0,
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false
            ],
            'parameters' => [
                'timeout' => 2.5,
                'read_write_timeout' => 3.0
            ]
        ]);

        $this->redis->ping();
    }

    public function set($key, $value, $ttl = null)
    {
        try {
            $finalKey = $this->prefix . $key;
            $serializedValue = is_array($value) || is_object($value) 
                ? json_encode($value, JSON_UNESCAPED_UNICODE) 
                : $value;

            if ($serializedValue === false) {
                error_log("Redis set failed: json_encode error for key {$key}");
                return false;
            }

            $ttl = $ttl ?? $this->default_ttl;

            $this->redis->setex($finalKey, $ttl, $serializedValue);
            return true;
        } catch (Exception $e) {
            error_log("Redis set failed for key {$key}: " . $e->getMessage());
            return false;
        }
    }

    public function get($key, $decode_json = true)
    {
        try {
            $finalKey = $this->prefix . $key;
            $value = $this->redis->get($finalKey);

            if ($value === null) {
                return null;
            }

            if ($decode_json && is_string($value) && in_array($value[0], ['{', '['])) {
                return json_decode($value, true);
            }

            return $value;
        } catch (Exception $e) {
            error_log("Redis get failed for key {$key}: " . $e->getMessage());
            return null;
        }
    }

    public function delete($key)
    {
        try {
            $finalKey = $this->prefix . $key;
            return $this->redis->del($finalKey) > 0;
        } catch (Exception $e) {
            error_log("Redis delete failed for key {$key}: " . $e->getMessage());
            return false;
        }
    }

    public function exists($key)
    {
        try {
            $finalKey = $this->prefix . $key;
            return $this->redis->exists($finalKey) > 0;
        } catch (Exception $e) {
            error_log("Redis exists failed for key {$key}: " . $e->getMessage());
            return false;
        }
    }

    public function increment($key, $increment = 1, $ttl = 60)
    {
        try {
            $finalKey = $this->prefix . $key;
            $newValue = $this->redis->incrby($finalKey, $increment);

            if ($newValue == $increment) {
                $this->redis->expire($finalKey, $ttl);
            }

            return (int)$newValue;
        } catch (Exception $e) {
            error_log("Redis increment failed for key {$key}: " . $e->getMessage());
            return 0;
        }
    }

    // New methods needed for rate limiting
    public function incr($key)
    {
        try {
            $finalKey = $this->prefix . $key;
            return $this->redis->incr($finalKey);
        } catch (Exception $e) {
            error_log("Redis incr failed for key {$key}: " . $e->getMessage());
            return 0;
        }
    }

    public function expire($key, $seconds)
    {
        try {
            $finalKey = $this->prefix . $key;
            return $this->redis->expire($finalKey, $seconds) === 1;
        } catch (Exception $e) {
            error_log("Redis expire failed for key {$key}: " . $e->getMessage());
            return false;
        }
    }

    public function ttl($key)
    {
        try {
            $finalKey = $this->prefix . $key;
            $ttl = $this->redis->ttl($finalKey);
            return $ttl >= 0 ? $ttl : 0;
        } catch (Exception $e) {
            error_log("Redis ttl failed for key {$key}: " . $e->getMessage());
            return 0;
        }
    }

    // Additional utility methods that might be useful
    public function setex($key, $seconds, $value)
    {
        return $this->set($key, $value, $seconds);
    }

    public function decr($key)
    {
        try {
            $finalKey = $this->prefix . $key;
            return $this->redis->decr($finalKey);
        } catch (Exception $e) {
            error_log("Redis decr failed for key {$key}: " . $e->getMessage());
            return 0;
        }
    }

    public function flushAll()
    {
        try {
            // Note: Be careful with this method as it clears ALL keys
            return $this->redis->flushall();
        } catch (Exception $e) {
            error_log("Redis flushall failed: " . $e->getMessage());
            return false;
        }
    }
}