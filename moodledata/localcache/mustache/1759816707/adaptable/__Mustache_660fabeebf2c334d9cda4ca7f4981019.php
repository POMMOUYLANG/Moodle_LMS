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
                        'drawerclasses' => array($this, 'block846e88908cced1a30489d0881e24963c'),
                        'drawercontent' => array($this, 'blockC45667d735f5428ff95d51699485317c'),
                        'drawerpreferencename' => array($this, 'block24fc4cc7410bc884a3b9fba5f26dc7b9'),
                        'drawerstate' => array($this, 'block50edc768dc89ca8acf3847ea0601b065'),
                        'tooltipplacement' => array($this, 'block8ec1eb102a29a8cdab12e85151cc1d7d'),
                        'closebuttontext' => array($this, 'blockB4af9e87e93f5671094075e5a836e0e5'),
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

    public function block846e88908cced1a30489d0881e24963c($context)
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

    public function blockC45667d735f5428ff95d51699485317c($context)
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

    public function block50edc768dc89ca8acf3847ea0601b065($context)
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

    public function block8ec1eb102a29a8cdab12e85151cc1d7d($context)
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

    public function blockB4af9e87e93f5671094075e5a836e0e5($context)
    {
        $indent = $buffer = '';
        $value = $context->find('str');
        $buffer .= $this->sectionD8c059d8e564034fcd5a4fcfed7ed8eb($context, $indent, $value);
    
        return $buffer;
    }
}
