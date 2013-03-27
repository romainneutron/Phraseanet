<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2013 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\Form\Login;

use Alchemy\Phrasea\Utilities\String\Camelizer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

// write tests
class PhraseaRegisterForm extends AbstractType
{
    private $available;
    private $params;
    private $camelizer;

    public function __construct(array $available, array $params = array(), Camelizer $camelizer = null)
    {
        $this->available = $available;
        $this->params = $params;
        $this->camelizer = $camelizer ?: new Camelizer();
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('email', 'email', array(
            'label'       => _('E-mail'),
            'required'    => true,
            'constraints' => array(
                new Assert\NotBlank(),
            ),
        ));

        $builder->add('password', 'password', array(
            'label' => _('Password'),
            'required' => true,
            'constraints' => array(
                new Assert\NotBlank()
            )
        ));

        $builder->add('passwordConfirm', 'password', array(
            'label' => _('Password (confirmation)'),
            'required' => false,
            'constraints' => array(
                new Assert\NotBlank()
            )
        ));

        $builder->add('accept-tou', 'checkbox', array(
            'mapped'   => false,
            'required' => false
        ));

        foreach ($this->params as $param) {
            $name = $param['name'];
            if (isset($this->available[$name])) {
                $builder->add(
                    $this->camelizer->camelize($name, '-'),
                    $this->getType($name),
                    array(
                        'label'       => $this->getLabel($name),
                        'required'    => $param['required'],
                        'constraints' => $this->getConstraints($name),//, $param['constraints']),
                    )
                );
            }
        }
    }

    public function getName()
    {
        return null;
    }

    private function getType($name)
    {
        return $this->available[$name]['type'];
    }

    private function getLabel($name)
    {
        return $this->available[$name]['label'];
    }

    private function getConstraints($name, array $constraints = array())
    {
        return isset($this->available[$name]['constraints']) ? $this->available[$name]['constraints'] : array();
    }
}
