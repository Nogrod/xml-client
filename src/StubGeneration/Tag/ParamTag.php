<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2016 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Nogrod\XMLClient\StubGeneration\Tag;

use Laminas\Code\Generator\DocBlock\Tag\ParamTag as ParamTagTag;

class ParamTag extends ParamTagTag
{
    protected $default;

    public function setDefault($default)
    {
        $this->default = $default;
    }

    /**
     * @return string
     */
    public function generate()
    {
        return '@param'
            . (!empty($this->types) ? ' ' . $this->getTypesAsString() : '')
            . (!empty($this->variableName) ? ' $' . $this->variableName : '')
            . (!empty($this->default) ? ' = ' . $this->default : '')
            . (!empty($this->description) ? ' ' . $this->description : '');
    }

    public function generateForMethod()
    {
        return (!empty($this->types) ? $this->getTypesAsString() : '')
            . (!empty($this->variableName) ? ' $' . $this->variableName : '')
            . (!empty($this->default) ? ' = ' . $this->default : '');
    }
}
