<?php

class __Mustache_660fabeebf2c334d9cda4ca7f4981019 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $value = $context->find('courseindex');
        $buffer .= $this->section27b2ba7c925d48325015fc4d7c1aca77($context, $indent, $value);

        return $buffer;
    }

    private function section6f33152a41341e2c397de871a1796b75(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'right';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'right';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section1cb47d3ed9b97c6d6d496d2358bec253(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' show';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' show';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionB41f9fd909a0b705eeb8f603ddd82d78(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' d-none';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' d-none';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section0aa6fe3b3c41579e49bb7bcc3c6a53a1(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'left';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'left';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionD8c059d8e564034fcd5a4fcfed7ed8eb(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'closecourseindex, core';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'closecourseindex, core';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section27b2ba7c925d48325015fc4d7c1aca77(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    {{< theme_boost/drawer }}
        {{$id}}theme_adaptable-drawers-courseindex{{/id}}
        {{$drawerclasses}}drawer drawer-{{#left}}right{{/left}}{{^left}}left{{/left}}{{#courseindexopen}} show{{/courseindexopen}}{{#stickynavbar}} d-none{{/stickynavbar}}{{/drawerclasses}}
        {{$drawercontent}}
            {{{courseindex}}}
        {{/drawercontent}}
        {{$drawerpreferencename}}drawer-open-index{{/drawerpreferencename}}
        {{$drawerstate}}show-drawer-{{#left}}right{{/left}}{{^left}}left{{/left}}{{/drawerstate}}
        {{$tooltipplacement}}{{#left}}left{{/left}}{{^left}}right{{/left}}{{/tooltipplacement}}
        {{$closebuttontext}}{{#str}}closecourseindex, core{{/str}}{{/closebuttontext}}
    {{/ theme_boost/drawer}}
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    ';
                if ($parent = $this->mustache->loadPartial('theme_boost/drawer')) {
                    $context->pushBlockContext(array(
                        'id' => array($this, 'block599bcac39874a00a5d5b5d71974613ac'),
                        'drawerclasses' => array($this, 'blockE038400ff1f2cd8689fdc3ef0a7d0d73'),
                        'drawercontent' => array($this, 'block9fff240262f0ea356c2062c52093f306'),
                        'drawerpreferencename' => array($this, 'block24fc4cc7410bc884a3b9fba5f26dc7b9'),
                        'drawerstate' => array($this, 'blockD55a52db5935c86d9d4ea21af4cdb4b8'),
                        'tooltipplacement' => array($this, 'block88bf0bcda57efbf048c636bfb0d07909'),
                        'closebuttontext' => array($this, 'block9ac4e5859f44816b862545bc2c6e6a46'),
                    ));
                    $buffer .= $parent->renderInternal($context, $indent);
                    $context->popBlockContext();
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    public function block599bcac39874a00a5d5b5d71974613ac($context)
    {
        $indent = $buffer = '';
        $buffer .= 'theme_adaptable-drawers-courseindex';
    
        return $buffer;
    }

    public function blockE038400ff1f2cd8689fdc3ef0a7d0d73($context)
    {
        $indent = $buffer = '';
        $buffer .= 'drawer drawer-';
        $value = $context->find('left');
        $buffer .= $this->section6f33152a41341e2c397de871a1796b75($context, $indent, $value);
        $value = $context->find('left');
        if (empty($value)) {
            
            $buffer .= 'left';
        }
        $value = $context->find('courseindexopen');
        $buffer .= $this->section1cb47d3ed9b97c6d6d496d2358bec253($context, $indent, $value);
        $value = $context->find('stickynavbar');
        $buffer .= $this->sectionB41f9fd909a0b705eeb8f603ddd82d78($context, $indent, $value);
    
        return $buffer;
    }

    public function block9fff240262f0ea356c2062c52093f306($context)
    {
        $indent = $buffer = '';
        $buffer .= '            ';
        $value = $this->resolveValue($context->find('courseindex'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '
';
    
        return $buffer;
    }

    public function block24fc4cc7410bc884a3b9fba5f26dc7b9($context)
    {
        $indent = $buffer = '';
        $buffer .= $indent . 'drawer-open-index';
    
        return $buffer;
    }

    public function blockD55a52db5935c86d9d4ea21af4cdb4b8($context)
    {
        $indent = $buffer = '';
        $buffer .= 'show-drawer-';
        $value = $context->find('left');
        $buffer .= $this->section6f33152a41341e2c397de871a1796b75($context, $indent, $value);
        $value = $context->find('left');
        if (empty($value)) {
            
            $buffer .= 'left';
        }
    
        return $buffer;
    }

    public function block88bf0bcda57efbf048c636bfb0d07909($context)
    {
        $indent = $buffer = '';
        $value = $context->find('left');
        $buffer .= $this->section0aa6fe3b3c41579e49bb7bcc3c6a53a1($context, $indent, $value);
        $value = $context->find('left');
        if (empty($value)) {
            
            $buffer .= 'right';
        }
    
        return $buffer;
    }

    public function block9ac4e5859f44816b862545bc2c6e6a46($context)
    {
        $indent = $buffer = '';
        $value = $context->find('str');
        $buffer .= $this->sectionD8c059d8e564034fcd5a4fcfed7ed8eb($context, $indent, $value);
    
        return $buffer;
    }
}
