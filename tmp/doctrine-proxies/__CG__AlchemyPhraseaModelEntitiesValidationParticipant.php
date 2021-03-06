<?php

namespace Alchemy\Phrasea\Model\Proxies\__CG__\Alchemy\Phrasea\Model\Entities;

/**
 * DO NOT EDIT THIS FILE - IT WAS CREATED BY DOCTRINE'S PROXY GENERATOR
 */
class ValidationParticipant extends \Alchemy\Phrasea\Model\Entities\ValidationParticipant implements \Doctrine\ORM\Proxy\Proxy
{
    /**
     * @var \Closure the callback responsible for loading properties in the proxy object. This callback is called with
     *      three parameters, being respectively the proxy object to be initialized, the method that triggered the
     *      initialization process and an array of ordered parameters that were passed to that method.
     *
     * @see \Doctrine\Common\Persistence\Proxy::__setInitializer
     */
    public $__initializer__;

    /**
     * @var \Closure the callback responsible of loading properties that need to be copied in the cloned object
     *
     * @see \Doctrine\Common\Persistence\Proxy::__setCloner
     */
    public $__cloner__;

    /**
     * @var boolean flag indicating if this object was already initialized
     *
     * @see \Doctrine\Common\Persistence\Proxy::__isInitialized
     */
    public $__isInitialized__ = false;

    /**
     * @var array properties to be lazy loaded, with keys being the property
     *            names and values being their default values
     *
     * @see \Doctrine\Common\Persistence\Proxy::__getLazyProperties
     */
    public static $lazyPropertiesDefaults = array();



    /**
     * @param \Closure $initializer
     * @param \Closure $cloner
     */
    public function __construct($initializer = null, $cloner = null)
    {

        $this->__initializer__ = $initializer;
        $this->__cloner__      = $cloner;
    }







    /**
     * 
     * @return array
     */
    public function __sleep()
    {
        if ($this->__isInitialized__) {
            return array('__isInitialized__', 'id', 'is_aware', 'is_confirmed', 'can_agree', 'can_see_others', 'reminded', 'datas', 'session', 'user');
        }

        return array('__isInitialized__', 'id', 'is_aware', 'is_confirmed', 'can_agree', 'can_see_others', 'reminded', 'datas', 'session', 'user');
    }

    /**
     * 
     */
    public function __wakeup()
    {
        if ( ! $this->__isInitialized__) {
            $this->__initializer__ = function (ValidationParticipant $proxy) {
                $proxy->__setInitializer(null);
                $proxy->__setCloner(null);

                $existingProperties = get_object_vars($proxy);

                foreach ($proxy->__getLazyProperties() as $property => $defaultValue) {
                    if ( ! array_key_exists($property, $existingProperties)) {
                        $proxy->$property = $defaultValue;
                    }
                }
            };

        }
    }

    /**
     * 
     */
    public function __clone()
    {
        $this->__cloner__ && $this->__cloner__->__invoke($this, '__clone', array());
    }

    /**
     * Forces initialization of the proxy
     */
    public function __load()
    {
        $this->__initializer__ && $this->__initializer__->__invoke($this, '__load', array());
    }

    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific loading logic
     */
    public function __isInitialized()
    {
        return $this->__isInitialized__;
    }

    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific loading logic
     */
    public function __setInitialized($initialized)
    {
        $this->__isInitialized__ = $initialized;
    }

    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific loading logic
     */
    public function __setInitializer(\Closure $initializer = null)
    {
        $this->__initializer__ = $initializer;
    }

    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific loading logic
     */
    public function __getInitializer()
    {
        return $this->__initializer__;
    }

    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific loading logic
     */
    public function __setCloner(\Closure $cloner = null)
    {
        $this->__cloner__ = $cloner;
    }

    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific cloning logic
     */
    public function __getCloner()
    {
        return $this->__cloner__;
    }

    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific loading logic
     * @static
     */
    public function __getLazyProperties()
    {
        return self::$lazyPropertiesDefaults;
    }

    
    /**
     * {@inheritDoc}
     */
    public function getId()
    {
        if ($this->__isInitialized__ === false) {
            return (int)  parent::getId();
        }


        $this->__initializer__ && $this->__initializer__->__invoke($this, 'getId', array());

        return parent::getId();
    }

    /**
     * {@inheritDoc}
     */
    public function setUser(\Alchemy\Phrasea\Model\Entities\User $user)
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'setUser', array($user));

        return parent::setUser($user);
    }

    /**
     * {@inheritDoc}
     */
    public function getUser()
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'getUser', array());

        return parent::getUser();
    }

    /**
     * {@inheritDoc}
     */
    public function setIsAware($isAware)
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'setIsAware', array($isAware));

        return parent::setIsAware($isAware);
    }

    /**
     * {@inheritDoc}
     */
    public function getIsAware()
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'getIsAware', array());

        return parent::getIsAware();
    }

    /**
     * {@inheritDoc}
     */
    public function setIsConfirmed($isConfirmed)
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'setIsConfirmed', array($isConfirmed));

        return parent::setIsConfirmed($isConfirmed);
    }

    /**
     * {@inheritDoc}
     */
    public function getIsConfirmed()
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'getIsConfirmed', array());

        return parent::getIsConfirmed();
    }

    /**
     * {@inheritDoc}
     */
    public function setCanAgree($canAgree)
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'setCanAgree', array($canAgree));

        return parent::setCanAgree($canAgree);
    }

    /**
     * {@inheritDoc}
     */
    public function getCanAgree()
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'getCanAgree', array());

        return parent::getCanAgree();
    }

    /**
     * {@inheritDoc}
     */
    public function setCanSeeOthers($canSeeOthers)
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'setCanSeeOthers', array($canSeeOthers));

        return parent::setCanSeeOthers($canSeeOthers);
    }

    /**
     * {@inheritDoc}
     */
    public function getCanSeeOthers()
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'getCanSeeOthers', array());

        return parent::getCanSeeOthers();
    }

    /**
     * {@inheritDoc}
     */
    public function setReminded($reminded)
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'setReminded', array($reminded));

        return parent::setReminded($reminded);
    }

    /**
     * {@inheritDoc}
     */
    public function getReminded()
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'getReminded', array());

        return parent::getReminded();
    }

    /**
     * {@inheritDoc}
     */
    public function addData(\Alchemy\Phrasea\Model\Entities\ValidationData $datas)
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'addData', array($datas));

        return parent::addData($datas);
    }

    /**
     * {@inheritDoc}
     */
    public function removeData(\Alchemy\Phrasea\Model\Entities\ValidationData $datas)
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'removeData', array($datas));

        return parent::removeData($datas);
    }

    /**
     * {@inheritDoc}
     */
    public function getDatas()
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'getDatas', array());

        return parent::getDatas();
    }

    /**
     * {@inheritDoc}
     */
    public function setSession(\Alchemy\Phrasea\Model\Entities\ValidationSession $session = NULL)
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'setSession', array($session));

        return parent::setSession($session);
    }

    /**
     * {@inheritDoc}
     */
    public function getSession()
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'getSession', array());

        return parent::getSession();
    }

    /**
     * {@inheritDoc}
     */
    public function isReleasable()
    {

        $this->__initializer__ && $this->__initializer__->__invoke($this, 'isReleasable', array());

        return parent::isReleasable();
    }

}
