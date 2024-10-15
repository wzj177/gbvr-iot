<?php


namespace CoreW\Business\VIP;


use CoreW\Business\User\CurrentUserInterface;
use CoreW\Exception\UnexpectedValueException;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @method string getUserIdentifier()
 */
class CurrentUser implements CurrentUserInterface, EquatableInterface, \ArrayAccess, \Serializable
{
    protected $data = [];
    protected $context = [];

    public function getRoles()
    {
        return [];
    }

    public function offsetExists($offset)
    {
        return $this->__isset($offset);
    }

    public function offsetGet($offset)
    {
        return $this->__get($offset);
    }

    public function offsetSet($offset, $value)
    {
        return $this->__set($offset, $value);
    }

    public function offsetUnset($offset)
    {
        return $this->__unset($offset);
    }

    public function serialize()
    {
        return serialize($this->data);
    }

    public function unserialize($serialized)
    {
        $this->data = unserialize($serialized);
    }

    public function __set($name, $value)
    {
        $this->data[$name] = $value;

        return $this;
    }

    public function __get($name)
    {
        if (array_key_exists($name, $this->data)) {
            return $this->data[$name];
        }
        throw new UnexpectedValueException("{$name} is not exist in CurrentUser.");
    }

    public function __isset($name)
    {
        return isset($this->data[$name]);
    }

    public function __unset($name)
    {
        unset($this->data[$name]);
    }

    public function fromArray(array $user)
    {
        $this->data = $user;

        return $this;
    }

    public function isEqualTo(UserInterface $user)
    {
        if ($this->email !== $user->getUsername()) {
            return false;
        }

        return true;
    }


    /**
     * @return string
     */
    public function getUUID(): string
    {
        return $this->uuid;
    }

    public function getPassword()
    {
        return $this->password;
    }

    public function getSalt()
    {
        return $this->salt;
    }

    public function getUsername()
    {
        return $this->email;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getRole()
    {
        return (int)$this->role;
    }

    public function isLogin()
    {
        return !empty($this->id);
    }


    public function eraseCredentials()
    {
    }

    public function toArray()
    {
        return $this->data;
    }


    public function setContext($name, $value)
    {
        $this->context[$name] = $value;
    }

    public function getContext($name)
    {
        return $this->context[$name] ?? null;
    }
}