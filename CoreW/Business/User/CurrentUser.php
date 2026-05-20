<?php

namespace CoreW\Business\User;

use CoreW\Exception\UnexpectedValueAssistant;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @method string getUserIdentifier()
 */
class CurrentUser implements CurrentUserInterface, EquatableInterface, \ArrayAccess
{
    protected array $data = [];
    protected array $permissions = [];
    protected array $context = [];

    // ———————— ArrayAccess 实现 ————————

    public function offsetExists(mixed $offset) : bool
    {
        return $this->__isset($offset);
    }

    public function offsetGet(mixed $offset) : mixed
    {
        return $this->__get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value) : void
    {
        $this->__set($offset, $value);
    }

    public function offsetUnset(mixed $offset) : void
    {
        $this->__unset($offset);
    }

    // ———————— 魔术方法 ————————

    public function __set($name, $value) : void
    {
        $this->data[$name] = $value;
    }

    public function __get($name) : mixed
    {
        if (array_key_exists($name, $this->data)) {
            return $this->data[$name];
        }
        throw new UnexpectedValueException("{$name} is not exist in CurrentUser.");
    }

    public function __isset($name) : bool
    {
        return isset($this->data[$name]);
    }

    public function __unset($name) : void
    {
        unset($this->data[$name]);
    }

    // ———————— 序列化支持（PHP 8.1+ 推荐方式） ————————

    public function __serialize() : array
    {
        return $this->data;
    }

    public function __unserialize(array $data) : void
    {
        $this->data = $data;
    }

    // ———————— 业务方法 ————————

    public function fromArray(array $user) : static
    {
        $this->data = $user;
        return $this;
    }

    public function isEqualTo(UserInterface $user) : bool
    {
        if ($this->email !== $user->getUsername()) {
            return false;
        }

        $thisRoles = $this->getRoles();
        $userRoles = $user->getRoles();

        if (array_diff($thisRoles, $userRoles) || array_diff($userRoles, $thisRoles)) {
            return false;
        }

        return true;
    }

    public function getRoles() : array
    {
        return $this->data['roles'] ?? [];
    }

    public function getPassword() : ?string
    {
        return $this->data['password'] ?? null;
    }

    public function getSalt() : ?string
    {
        return $this->data['salt'] ?? null;
    }

    public function getUsername() : ?string
    {
        return $this->data['email'] ?? null;
    }

    public function getId() : ?int
    {
        return $this->data['id'] ?? null;
    }

    public function isLogin() : bool
    {
        return !empty($this->data['id']);
    }

    public function isSuperAdmin() : bool
    {
        return in_array('ROLE_SUPER_ADMIN', $this->getRoles(), true);
    }

    public function eraseCredentials() : void
    {
        // Clear sensitive data if needed
        unset($this->data['password']);
    }

    public function toArray() : array
    {
        return $this->data;
    }

    public function setPermissions(array $permissions) : static
    {
        $this->permissions = $permissions;
        return $this;
    }

    public function getPermissions() : array
    {
        return $this->permissions;
    }

    public function setContext(string $name, mixed $value) : void
    {
        $this->context[$name] = $value;
    }

    public function getContext(string $name) : mixed
    {
        return $this->context[$name] ?? null;
    }
}